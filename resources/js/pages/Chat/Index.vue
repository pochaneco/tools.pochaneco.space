<script setup lang="ts">
import ConversationList, { type ConversationSummary } from '@/components/Chat/ConversationList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import chatRoutes from '@/routes/chat';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Menu } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('dashboard.title'), href: dashboard().url },
    { title: t('chat.title'), href: chatRoutes.index().url },
];

type MessageRole = 'user' | 'assistant' | 'system';

type Message = {
    id: string;
    text: string;
    ts: string;
    role: MessageRole;
    streaming?: boolean;
};

type StreamDeltaEvent = {
    type: 'delta';
    text: string;
};

type StreamDoneEvent = {
    type: 'done';
    conversation_id?: number | string;
    message_id?: number | string;
};

type StreamErrorEvent = {
    type: 'error';
    message?: string;
};

type StreamEvent = StreamDeltaEvent | StreamDoneEvent | StreamErrorEvent;

type ChatState = {
    messages: Message[];
    input: string;
    streaming: boolean;
    error: string | null;
    conversationId: number | string | null;
};

type LoadedMessage = {
    id: number;
    role: string;
    content: string;
    created_at: string | null;
};

type LoadedConversation = {
    id: number;
    title: string | null;
    model: string | null;
    messages: LoadedMessage[];
};

const CHAT_MESSAGE_ENDPOINT = chatRoutes.message.url();
const CONVERSATIONS_INDEX_URL = '/chat/conversations';

const state = reactive<ChatState>({
    messages: [],
    input: '',
    streaming: false,
    error: null,
    conversationId: null,
});

const conversations = ref<ConversationSummary[]>([]);
const drawerOpen = ref(false);

let abortController: AbortController | null = null;
let currentAssistantId: string | null = null;
const scrollEl = ref<HTMLElement | null>(null);
const inputRef = ref<InstanceType<typeof Input> | null>(null);

function genId(): string {
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

function scrollToBottom() {
    nextTick(() => {
        if (scrollEl.value) {
            scrollEl.value.scrollTop = scrollEl.value.scrollHeight;
        }
    });
}

function getCsrfToken(): string | null {
    const meta = document.querySelector('meta[name="csrf-token"]');
    const metaToken = meta?.getAttribute('content');
    if (metaToken) {
        return metaToken;
    }
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch {
            return match[1];
        }
    }
    return null;
}

function findAssistantMessage(id: string): Message | undefined {
    return state.messages.find((m) => m.id === id);
}

function appendDelta(assistantId: string, text: string) {
    const msg = findAssistantMessage(assistantId);
    if (!msg) return;
    msg.text += text;
    scrollToBottom();
}

function finalizeAssistant(assistantId: string) {
    const msg = findAssistantMessage(assistantId);
    if (msg) {
        msg.streaming = false;
    }
}

function parseSseBuffer(buffer: string): { events: ParsedSseEvent[]; rest: string } {
    const events: ParsedSseEvent[] = [];
    let rest = buffer;

    while (true) {
        const sepIndex = rest.indexOf('\n\n');
        if (sepIndex === -1) break;

        const rawEvent = rest.slice(0, sepIndex);
        rest = rest.slice(sepIndex + 2);

        const parsed = parseSingleSseEvent(rawEvent);
        if (parsed) {
            events.push(parsed);
        }
    }

    return { events, rest };
}

type ParsedSseEvent = {
    event: string;
    data: string;
};

function parseSingleSseEvent(raw: string): ParsedSseEvent | null {
    const lines = raw.split('\n');
    let eventName = 'message';
    const dataLines: string[] = [];

    for (const line of lines) {
        if (line === '' || line.startsWith(':')) continue;
        const colonIndex = line.indexOf(':');
        if (colonIndex === -1) continue;
        const field = line.slice(0, colonIndex);
        let value = line.slice(colonIndex + 1);
        if (value.startsWith(' ')) value = value.slice(1);

        if (field === 'event') {
            eventName = value;
        } else if (field === 'data') {
            dataLines.push(value);
        }
    }

    if (dataLines.length === 0) return null;
    return { event: eventName, data: dataLines.join('\n') };
}

function handleSseEvent(assistantId: string, evt: ParsedSseEvent): boolean {
    if (evt.event === 'done') {
        return true;
    }

    let payload: StreamEvent | null = null;
    try {
        payload = JSON.parse(evt.data) as StreamEvent;
    } catch {
        return false;
    }

    if (!payload) return false;

    if (payload.type === 'delta' && typeof (payload as StreamDeltaEvent).text === 'string') {
        appendDelta(assistantId, (payload as StreamDeltaEvent).text);
        return false;
    }

    if (payload.type === 'error') {
        state.error = (payload as StreamErrorEvent).message ?? t('chat.connection_error');
        return true;
    }

    if (payload.type === 'done') {
        const done = payload as StreamDoneEvent;
        if (done.conversation_id !== undefined) {
            state.conversationId = done.conversation_id;
        }
        return true;
    }

    return false;
}

async function streamAssistantReply(userMessage: string, assistantId: string) {
    abortController = new AbortController();
    const csrf = getCsrfToken();

    let response: Response;
    try {
        response = await fetch(CHAT_MESSAGE_ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf, 'X-XSRF-TOKEN': csrf } : {}),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                message: userMessage,
                conversation_id: state.conversationId,
            }),
            signal: abortController.signal,
        });
    } catch (err) {
        if ((err as Error).name === 'AbortError') {
            finalizeAssistant(assistantId);
            return;
        }
        state.error = t('chat.connection_error');
        finalizeAssistant(assistantId);
        return;
    }

    if (!response.ok) {
        if (response.status === 404) {
            state.error = t('chat.backend_not_ready');
        } else {
            state.error = t('chat.connection_error');
        }
        finalizeAssistant(assistantId);
        return;
    }

    if (!response.body) {
        state.error = t('chat.connection_error');
        finalizeAssistant(assistantId);
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const { events, rest } = parseSseBuffer(buffer);
            buffer = rest;

            for (const evt of events) {
                const shouldStop = handleSseEvent(assistantId, evt);
                if (shouldStop) {
                    await reader.cancel().catch(() => {});
                    return;
                }
            }
        }

        if (buffer.trim().length > 0) {
            const { events } = parseSseBuffer(buffer + '\n\n');
            for (const evt of events) {
                handleSseEvent(assistantId, evt);
            }
        }
    } catch (err) {
        if ((err as Error).name !== 'AbortError') {
            state.error = t('chat.connection_error');
        }
    } finally {
        finalizeAssistant(assistantId);
    }
}

function stopStream() {
    if (abortController) {
        abortController.abort();
        abortController = null;
    }
    if (currentAssistantId) {
        finalizeAssistant(currentAssistantId);
    }
    state.streaming = false;
    currentAssistantId = null;
}

async function send() {
    const text = state.input.trim();
    if (!text || state.streaming) return;

    state.error = null;

    const userMessage: Message = {
        id: genId(),
        text,
        ts: new Date().toISOString(),
        role: 'user',
    };
    state.messages.push(userMessage);
    state.input = '';

    const assistantMessage: Message = {
        id: genId(),
        text: '',
        ts: new Date().toISOString(),
        role: 'assistant',
        streaming: true,
    };
    state.messages.push(assistantMessage);
    currentAssistantId = assistantMessage.id;
    state.streaming = true;
    scrollToBottom();

    try {
        await streamAssistantReply(text, assistantMessage.id);
    } finally {
        state.streaming = false;
        abortController = null;
        currentAssistantId = null;
        // Refresh the sidebar so the new (or touched) conversation floats up
        loadConversations();
    }
}

async function loadConversations() {
    try {
        const res = await fetch(CONVERSATIONS_INDEX_URL, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) return;
        conversations.value = (await res.json()) as ConversationSummary[];
    } catch {
        // Non-fatal; list can retry later.
    }
}

function mapDbMessageToUi(m: LoadedMessage): Message {
    return {
        id: `db-${m.id}`,
        text: m.content,
        ts: m.created_at ?? new Date().toISOString(),
        role: (m.role as MessageRole) ?? 'user',
    };
}

async function selectConversation(id: number) {
    stopStream();
    drawerOpen.value = false;
    state.error = null;
    try {
        const res = await fetch(`/chat/conversations/${id}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) {
            state.error = t('chat.connection_error');
            return;
        }
        const data = (await res.json()) as LoadedConversation;
        state.messages = data.messages.map(mapDbMessageToUi);
        state.conversationId = data.id;
        scrollToBottom();
    } catch {
        state.error = t('chat.connection_error');
    }
}

async function deleteConversation(id: number) {
    // Confirm with the browser's native dialog for now. A shadcn AlertDialog
    // can replace this later without changing semantics.
    if (!window.confirm(t('chat.delete_confirm'))) {
        return;
    }
    const csrf = getCsrfToken();
    try {
        const res = await fetch(`/chat/conversations/${id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf, 'X-XSRF-TOKEN': csrf } : {}),
            },
        });
        if (!res.ok) {
            state.error = t('chat.connection_error');
            return;
        }
        if (state.conversationId === id) {
            newChat();
        }
        await loadConversations();
    } catch {
        state.error = t('chat.connection_error');
    }
}

async function renameConversation(id: number, title: string) {
    const csrf = getCsrfToken();
    try {
        const res = await fetch(`/chat/conversations/${id}`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf, 'X-XSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify({ title }),
        });
        if (!res.ok) {
            state.error = t('chat.connection_error');
            return;
        }
        await loadConversations();
    } catch {
        state.error = t('chat.connection_error');
    }
}

function newChat() {
    stopStream();
    state.messages = [];
    state.conversationId = null;
    state.error = null;
    drawerOpen.value = false;
    nextTick(() => {
        const el = (inputRef.value as unknown as { $el?: HTMLElement } | null)?.$el as HTMLInputElement | undefined;
        el?.focus?.();
    });
}

onMounted(() => {
    loadConversations();
});

onBeforeUnmount(() => {
    if (abortController) {
        abortController.abort();
        abortController = null;
    }
});
</script>

<template>
    <Head :title="t('chat.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full min-h-0 flex-1">
            <!-- Desktop sidebar -->
            <aside class="hidden w-72 shrink-0 flex-col border-r md:flex">
                <ConversationList
                    :conversations="conversations"
                    :active-id="typeof state.conversationId === 'number' ? state.conversationId : null"
                    @select="selectConversation"
                    @rename="renameConversation"
                    @delete="deleteConversation"
                    @new="newChat"
                />
            </aside>

            <!-- Main chat pane -->
            <main class="flex min-h-0 flex-1 flex-col gap-4 p-4">
                <div class="flex items-center gap-2 md:hidden">
                    <Sheet v-model:open="drawerOpen">
                        <SheetTrigger as-child>
                            <Button variant="ghost" size="icon" :aria-label="t('chat.open_conversations')">
                                <Menu class="size-4" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-80 p-0 sm:max-w-sm">
                            <SheetHeader class="sr-only">
                                <SheetTitle>{{ t('chat.conversation_list') }}</SheetTitle>
                            </SheetHeader>
                            <ConversationList
                                :conversations="conversations"
                                :active-id="typeof state.conversationId === 'number' ? state.conversationId : null"
                                @select="selectConversation"
                                @rename="renameConversation"
                                @delete="deleteConversation"
                                @new="newChat"
                            />
                        </SheetContent>
                    </Sheet>
                    <span class="text-sm font-medium">{{ t('chat.conversation_list') }}</span>
                </div>

                <div ref="scrollEl" class="min-h-0 flex-1 overflow-auto rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="flex flex-col gap-3">
                        <div v-if="state.messages.length === 0" class="self-center text-xs opacity-70">
                            {{ t('chat.no_messages') }}
                        </div>
                        <div
                            v-for="m in state.messages"
                            :key="m.id"
                            class="max-w-[80%] rounded-md px-3 py-2 text-sm"
                            :class="m.role === 'user' ? 'self-end bg-primary/10' : 'self-start bg-muted'"
                        >
                            <div class="text-xs opacity-70">
                                {{ new Date(m.ts).toLocaleTimeString() }} ·
                                {{ m.role === 'user' ? t('chat.user') : t('chat.assistant') }}
                            </div>
                            <div class="whitespace-pre-wrap">
                                <template v-if="m.text.length > 0">{{ m.text }}</template>
                                <span v-if="m.streaming" class="chat-cursor" aria-hidden="true">&#9608;</span>
                            </div>
                        </div>
                        <div v-if="state.streaming" class="self-start text-xs opacity-70">
                            {{ t('chat.streaming') }}
                        </div>
                        <div v-if="state.error" class="self-start text-xs text-red-600">{{ state.error }}</div>
                    </div>
                </div>

                <form class="flex gap-2" @submit.prevent="send">
                    <Input
                        ref="inputRef"
                        v-model="state.input"
                        :placeholder="t('chat.input_placeholder')"
                        class="flex-1"
                        :disabled="state.streaming"
                    />
                    <Button type="submit" :disabled="state.streaming || !state.input.trim()">{{ t('chat.send') }}</Button>
                    <Button type="button" variant="secondary" @click="stopStream" :disabled="!state.streaming">
                        {{ t('chat.stop') }}
                    </Button>
                </form>
            </main>
        </div>
    </AppLayout>
</template>

<style scoped>
.chat-cursor {
    display: inline-block;
    margin-left: 2px;
    animation: chat-cursor-blink 1s steps(2, start) infinite;
    font-weight: 400;
    line-height: 1;
    opacity: 0.7;
}

@keyframes chat-cursor-blink {
    to {
        visibility: hidden;
    }
}
</style>
