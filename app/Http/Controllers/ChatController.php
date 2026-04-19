<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    use AuthorizesRequests;

    private const SYSTEM_INSTRUCTIONS = 'You are a helpful AI assistant. Respond in the same language as the user.';

    private const TITLE_MAX_LEN = 60;

    private const CONVERSATION_LIST_LIMIT = 100;

    public function index(): \Inertia\Response
    {
        return Inertia::render('Chat/Index', [
            'availableModels' => $this->availableModelsForFront(),
            'defaultModel' => config('ai.default_chat_model'),
        ]);
    }

    /**
     * Shape the chat model catalog into a front-end friendly payload.
     * Key = provider model id (what the SDK expects); value = label +
     * description we render in the selector UI.
     *
     * @return array<string, array{label: string, description: string}>
     */
    private function availableModelsForFront(): array
    {
        $models = config('ai.chat_models', []);

        $normalized = [];
        foreach ($models as $id => $meta) {
            $normalized[$id] = [
                'label' => (string) ($meta['label'] ?? $id),
                'description' => (string) ($meta['description'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * List the authenticated user's conversations, most recent first.
     */
    public function conversations(Request $request): JsonResponse
    {
        $conversations = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->limit(self::CONVERSATION_LIST_LIMIT)
            ->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'model' => $c->model,
                'updated_at' => optional($c->updated_at)->toIso8601String(),
                'messages_count' => (int) $c->messages_count,
            ])
            ->all();

        return response()->json($conversations);
    }

    /**
     * Show a single conversation with its messages.
     */
    public function conversation(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['messages' => fn ($q) => $q->orderBy('created_at')]);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'model' => $conversation->model,
            'updated_at' => optional($conversation->updated_at)->toIso8601String(),
            'messages' => $conversation->messages->map(fn (Message $m) => [
                'id' => $m->id,
                'role' => $m->role instanceof MessageRole ? $m->role->value : $m->role,
                'content' => $m->content,
                'model' => $m->model,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Rename a conversation.
     */
    public function renameConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
        ]);

        $conversation->update(['title' => $validated['title']]);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'model' => $conversation->model,
            'updated_at' => optional($conversation->updated_at)->toIso8601String(),
        ]);
    }

    /**
     * Delete a conversation. Messages cascade via the schema FK.
     */
    public function destroyConversation(Conversation $conversation): Response
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->noContent();
    }

    public function message(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'model' => ['nullable', 'string', Rule::in(array_keys(config('ai.chat_models', [])))],
        ]);

        $user = $request->user();

        // Load (or create) the conversation first so we can honor its stored
        // default model when the caller didn't pick one for this turn.
        $conversationId = $validated['conversation_id'] ?? null;
        $existingConversation = $conversationId !== null
            ? Conversation::where('user_id', $user->id)->where('id', $conversationId)->firstOrFail()
            : null;

        $model = $validated['model']
            ?? $existingConversation?->model
            ?? config('ai.default_chat_model');

        $conversation = $existingConversation
            ?? DB::transaction(fn () => Conversation::create([
                'user_id' => $user->id,
                'title' => Str::limit(trim($validated['message']), self::TITLE_MAX_LEN, ''),
                'model' => $model,
            ]));

        // Capture the pre-existing history *before* persisting the new user
        // message so the SDK call receives just the prior turns as context
        // and the fresh prompt is appended by the agent itself.
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $m) => $this->toSdkMessage($m))
            ->all();

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User->value,
            'content' => $validated['message'],
        ]);

        return $this->streamAgentResponse(
            conversation: $conversation,
            prompt: $validated['message'],
            history: $history,
            model: $model,
            allowTitleDispatch: true,
        );
    }

    /**
     * Delete the latest assistant message and re-stream a reply for the
     * latest user message in the conversation. The user's input is not
     * duplicated — we reuse the existing user message as the prompt.
     */
    public function regenerate(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'model' => ['nullable', 'string', Rule::in(array_keys(config('ai.chat_models', [])))],
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);
        $this->authorize('update', $conversation);

        // Drop the latest assistant reply (if any) so it can be replaced.
        $lastAssistant = $conversation->messages()
            ->where('role', MessageRole::Assistant->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $lastAssistant?->delete();

        // Find the prompt to replay. If the conversation has no user message
        // yet there is nothing meaningful to regenerate — bail out clearly.
        $lastUser = $conversation->messages()
            ->where('role', MessageRole::User->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($lastUser === null) {
            abort(422, 'No user message to regenerate from.');
        }

        // History = every remaining message *except* the last user message,
        // which we pass as the prompt. The SDK appends the prompt to the
        // context, so including it in history would duplicate the turn.
        $history = $conversation->messages()
            ->where('id', '!=', $lastUser->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $m) => $this->toSdkMessage($m))
            ->all();

        // Per-turn override wins; otherwise fall back to the conversation's
        // default model, and finally the app-wide default. Regeneration must
        // NOT rewrite `$conversation->model` — the conversation default is
        // the first-selected value and stays stable across regenerations so
        // later, un-selected turns keep the same baseline.
        $model = $validated['model']
            ?? $conversation->model
            ?? config('ai.default_chat_model');

        return $this->streamAgentResponse(
            conversation: $conversation,
            prompt: $lastUser->content,
            history: $history,
            model: $model,
            allowTitleDispatch: false,
        );
    }

    /**
     * Common SSE streaming pipeline shared by the message and regenerate
     * endpoints. Persists the final assistant reply on StreamEnd and
     * optionally dispatches the title-generation job.
     *
     * @param  array<int, \Laravel\Ai\Messages\Message>  $history
     */
    private function streamAgentResponse(
        Conversation $conversation,
        string $prompt,
        array $history,
        string $model,
        bool $allowTitleDispatch,
    ): StreamedResponse {
        $agent = new AnonymousAgent(
            instructions: self::SYSTEM_INSTRUCTIONS,
            messages: $history,
            tools: [],
        );

        $response = new StreamedResponse(function () use ($agent, $prompt, $conversation, $model, $allowTitleDispatch) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');

            $collected = '';
            $promptTokens = null;
            $completionTokens = null;

            try {
                $stream = $agent->stream($prompt, model: $model);

                foreach ($stream as $event) {
                    if (connection_aborted()) {
                        break;
                    }

                    if ($event instanceof TextDelta) {
                        $collected .= $event->delta;
                        $this->emit('message', ['type' => 'delta', 'text' => $event->delta]);

                        continue;
                    }

                    if ($event instanceof StreamEnd) {
                        $promptTokens = $event->usage->promptTokens;
                        $completionTokens = $event->usage->completionTokens;
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $this->emit('message', ['type' => 'error', 'message' => 'AI provider error']);
            }

            if ($collected !== '') {
                $assistantMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => MessageRole::Assistant->value,
                    'content' => $collected,
                    'model' => $model,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                ]);

                // Only schedule title generation for the *first* assistant
                // reply in a conversation (user + assistant = 2). Subsequent
                // turns leave the existing title alone, which also protects
                // titles that the user has renamed manually after the fact.
                //
                // Regeneration intentionally skips this dispatch entirely:
                // the conversation already had a title-worthy first turn at
                // some earlier point, and regenerating the very first reply
                // must not overwrite a user-renamed title either.
                if ($allowTitleDispatch && $conversation->messages()->count() === 2) {
                    GenerateConversationTitle::dispatch($conversation)->afterResponse();
                }

                $this->emit('done', [
                    'type' => 'done',
                    'conversation_id' => $conversation->id,
                    'message_id' => $assistantMessage->id,
                ]);
            } else {
                $this->emit('done', [
                    'type' => 'done',
                    'conversation_id' => $conversation->id,
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function toSdkMessage(Message $message): \Laravel\Ai\Messages\Message
    {
        return match ($message->role) {
            MessageRole::User => new UserMessage($message->content),
            MessageRole::Assistant => new AssistantMessage($message->content),
            MessageRole::System => new UserMessage($message->content),
        };
    }

    private function emit(string $event, array $payload): void
    {
        if ($event !== 'message') {
            echo 'event: '.$event."\n";
        }
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
        @ob_flush();
        @flush();
    }
}
