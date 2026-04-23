<?php

namespace App\Http\Controllers;

use App\Ai\ChatToolFactory;
use App\Ai\HistoryTruncator;
use App\Enums\MessageRole;
use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    use AuthorizesRequests;

    private const SYSTEM_INSTRUCTIONS = 'You are a helpful AI assistant. Respond in the same language as the user. '
        .'When the user asks about team-specific documentation, runbooks, policies, or internal decisions, call the '
        .'`search_team_knowledge` tool (if available) before answering, and cite the titles you used.';

    public function __construct(
        private readonly ChatToolFactory $chatToolFactory,
    ) {}

    private const TITLE_MAX_LEN = 60;

    private const CONVERSATION_LIST_LIMIT = 100;

    public function index(Request $request): \Inertia\Response
    {
        $user = $request->user();

        $teams = $user->teams()
            ->select('teams.id', 'teams.name')
            ->orderBy('teams.name')
            ->get()
            ->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
            ])
            ->all();

        // Default to the first team the user belongs to. EnsureUserHasDefaultTeam
        // guarantees every authenticated user has at least one team by the
        // time they reach the chat page, but keep the null guard for robustness.
        $defaultTeamId = $teams[0]['id'] ?? null;

        return Inertia::render('Chat/Index', [
            'availableModels' => $this->availableModelsForFront(),
            'defaultModel' => config('ai.default_chat_model'),
            'availableTeams' => $teams,
            'defaultTeamId' => $defaultTeamId,
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
            ->withSum('messages as prompt_tokens_sum', 'prompt_tokens')
            ->withSum('messages as completion_tokens_sum', 'completion_tokens')
            ->orderByDesc('updated_at')
            ->limit(self::CONVERSATION_LIST_LIMIT)
            ->get()
            ->map(function (Conversation $c) {
                $prompt = (int) ($c->prompt_tokens_sum ?? 0);
                $completion = (int) ($c->completion_tokens_sum ?? 0);

                return [
                    'id' => $c->id,
                    'title' => $c->title,
                    'model' => $c->model,
                    'team_id' => $c->team_id,
                    'updated_at' => optional($c->updated_at)->toIso8601String(),
                    'messages_count' => (int) $c->messages_count,
                    // Expose only the cumulative total in the list payload.
                    // The per-direction breakdown (prompt vs completion) is
                    // reserved for the detail view so the sidebar stays
                    // visually calm; clients that want the full breakdown
                    // fetch the single-conversation endpoint.
                    'usage' => [
                        'total' => $prompt + $completion,
                    ],
                ];
            })
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

        // Reduce over the already-loaded message collection instead of
        // issuing two extra `SUM()` queries. NULL token counts (e.g. user
        // messages, or assistant rows written before Phase 6) coalesce to
        // zero so empty conversations naturally report `total = 0`.
        $promptTokens = (int) $conversation->messages->sum(fn (Message $m) => (int) ($m->prompt_tokens ?? 0));
        $completionTokens = (int) $conversation->messages->sum(fn (Message $m) => (int) ($m->completion_tokens ?? 0));

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'model' => $conversation->model,
            'team_id' => $conversation->team_id,
            'updated_at' => optional($conversation->updated_at)->toIso8601String(),
            'messages' => $conversation->messages->map(fn (Message $m) => [
                'id' => $m->id,
                'role' => $m->role instanceof MessageRole ? $m->role->value : $m->role,
                'content' => $m->content,
                'model' => $m->model,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->all(),
            'usage' => [
                'prompt' => $promptTokens,
                'completion' => $completionTokens,
                'total' => $promptTokens + $completionTokens,
            ],
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
        $user = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'model' => ['nullable', 'string', Rule::in(array_keys(config('ai.chat_models', [])))],
            // team_id is a ULID — accept any string the user's team list
            // actually contains. The membership lookup below is what enforces
            // authorisation; validation only stops obvious garbage.
            'team_id' => ['nullable', 'string', 'max:40'],
        ]);

        // Load (or create) the conversation first so we can honor its stored
        // default model when the caller didn't pick one for this turn.
        $conversationId = $validated['conversation_id'] ?? null;
        $existingConversation = $conversationId !== null
            ? Conversation::where('user_id', $user->id)->where('id', $conversationId)->firstOrFail()
            : null;

        $model = $validated['model']
            ?? $existingConversation?->model
            ?? config('ai.default_chat_model');

        // Resolve the conversation's team. For existing conversations we
        // keep whatever team they were bound to at creation. For new
        // conversations: if the caller supplied a team they belong to we
        // use it; if they supplied a team they do NOT belong to we
        // downgrade to null (no RAG tool rather than silently pivoting
        // to another team they didn't ask for); if they didn't supply a
        // team at all we fall back to their first membership so the
        // default UX still gets RAG turned on.
        $teamId = $existingConversation?->team_id ?? $this->resolveTeamId($user, $validated['team_id'] ?? null);

        $conversation = $existingConversation
            ?? DB::transaction(fn () => Conversation::create([
                'user_id' => $user->id,
                'team_id' => $teamId,
                'title' => Str::limit(trim($validated['message']), self::TITLE_MAX_LEN, ''),
                'model' => $model,
            ]));

        // Capture the pre-existing history *before* persisting the new user
        // message so the SDK call receives just the prior turns as context
        // and the fresh prompt is appended by the agent itself.
        $historyModels = $conversation->messages()
            ->orderBy('created_at')
            ->get();

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User->value,
            'content' => $validated['message'],
        ]);

        return $this->streamAgentResponse(
            conversation: $conversation,
            prompt: $validated['message'],
            history: $historyModels,
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
        $historyModels = $conversation->messages()
            ->where('id', '!=', $lastUser->id)
            ->orderBy('created_at')
            ->get();

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
            history: $historyModels,
            model: $model,
            allowTitleDispatch: false,
        );
    }

    /**
     * Common SSE streaming pipeline shared by the message and regenerate
     * endpoints. Persists the final assistant reply on StreamEnd and
     * optionally dispatches the title-generation job.
     *
     * @param  iterable<Message>  $history  Persisted chat history (newest not yet included).
     */
    private function streamAgentResponse(
        Conversation $conversation,
        string $prompt,
        iterable $history,
        string $model,
        bool $allowTitleDispatch,
    ): StreamedResponse {
        // Trim the history to what fits inside the model's context window
        // before handing it to the SDK. The prompt itself is sent below
        // via `$agent->stream($prompt, ...)`, so we only budget for prior
        // turns here. When truncation happens we surface it to the
        // browser via a dedicated SSE event so the UI can warn the user.
        $truncator = app(HistoryTruncator::class);
        $truncation = $truncator->truncate(
            messages: $history,
            model: $model,
            systemPrompt: self::SYSTEM_INSTRUCTIONS,
        );

        $tools = $this->chatToolFactory->forConversation($conversation);

        $agent = new AnonymousAgent(
            instructions: self::SYSTEM_INSTRUCTIONS,
            messages: $truncation->keptMessages,
            tools: $tools,
        );

        $response = new StreamedResponse(function () use ($agent, $prompt, $conversation, $model, $allowTitleDispatch, $truncation) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');

            // Announce truncation *before* the first delta so the client
            // can surface the banner without waiting for the full reply.
            if ($truncation->wasTruncated()) {
                $this->emit('truncation', [
                    'type' => 'truncation',
                    'dropped' => $truncation->droppedCount,
                    'kept_tokens' => $truncation->tokensUsed,
                ]);
            }

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
                    // Piggyback the updated cumulative usage so the UI can
                    // refresh the header without a follow-up round trip.
                    'usage' => $this->usageTotals($conversation),
                ]);
            } else {
                $this->emit('done', [
                    'type' => 'done',
                    'conversation_id' => $conversation->id,
                    'usage' => $this->usageTotals($conversation),
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
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

    /**
     * Pick a team_id for a new conversation. Three-way semantics:
     *
     *   - $requested === null/''   → fall back to the user's first team
     *                                so out-of-the-box chats get RAG.
     *                                `EnsureUserHasDefaultTeam` guarantees
     *                                at least one membership exists.
     *   - member of $requested      → use it.
     *   - non-member of $requested  → return null (RAG disabled). We
     *                                 intentionally do NOT fall back to
     *                                 a different team the user didn't
     *                                 ask for — switching scopes silently
     *                                 would be more surprising than just
     *                                 turning the tool off.
     */
    private function resolveTeamId(User $user, ?string $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return $user->teams()->orderBy('teams.name')->value('teams.id');
        }

        $isMember = $user->teams()->whereKey($requested)->exists();

        return $isMember ? $requested : null;
    }

    /**
     * Cumulative prompt/completion/total token counts for a conversation.
     * Computed from `messages.prompt_tokens` + `messages.completion_tokens`
     * (sums ignore NULLs). Used both in the SSE `done` payload and as a
     * shared shape callers can consume.
     *
     * @return array{prompt: int, completion: int, total: int}
     */
    private function usageTotals(Conversation $conversation): array
    {
        $prompt = (int) $conversation->messages()->sum('prompt_tokens');
        $completion = (int) $conversation->messages()->sum('completion_tokens');

        return [
            'prompt' => $prompt,
            'completion' => $completion,
            'total' => $prompt + $completion,
        ];
    }
}
