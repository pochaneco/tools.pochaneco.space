<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        return Inertia::render('Chat/Index');
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
        ]);

        $user = $request->user();
        $model = config('ai.default_chat_model');

        $conversation = $this->resolveConversation($user->id, $validated['conversation_id'] ?? null, $validated['message'], $model);

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

        $agent = new AnonymousAgent(
            instructions: self::SYSTEM_INSTRUCTIONS,
            messages: $history,
            tools: [],
        );

        $response = new StreamedResponse(function () use ($agent, $validated, $conversation, $model) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');

            $collected = '';
            $promptTokens = null;
            $completionTokens = null;

            try {
                $stream = $agent->stream($validated['message'], model: $model);

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
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                ]);

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

    private function resolveConversation(int $userId, ?int $conversationId, string $firstMessage, ?string $model): Conversation
    {
        if ($conversationId !== null) {
            return Conversation::where('user_id', $userId)
                ->where('id', $conversationId)
                ->firstOrFail();
        }

        return DB::transaction(fn () => Conversation::create([
            'user_id' => $userId,
            'title' => Str::limit(trim($firstMessage), self::TITLE_MAX_LEN, ''),
            'model' => $model,
        ]));
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
