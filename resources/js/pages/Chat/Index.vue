<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import chatRoutes from '@/routes/chat';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('dashboard.title'), href: dashboard().url },
    { title: t('chat.title'), href: chatRoutes.index().url },
];

type MessageRole = 'user' | 'assistant';

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
    type?: 'done';
    [key: string]: unknown;
};

type StreamEvent = StreamDeltaEvent | StreamDoneEvent;

type ChatState = {
    messages: Message[];
    input: string;
    streaming: boolean;
    error: string | null;
    conversationId: string | null;
};

const CHAT_MESSAGE_ENDPOINT = '/chat/message';

const state = reactive<ChatState>({
    messages: [],
    input: '',
    streaming: false,
    error: null,
    conversationId: null,
});

let abortController: AbortController | null = null;
let currentAssistantId: string | null = null;
const scrollEl = ref<HTMLElement | null>(null);

// Simple non-crypto ID generator for prototype use
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
    // Fallback: Laravel also sets an XSRF-TOKEN cookie (URL-encoded)
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

/**
 * Parse an SSE payload buffer. SSE events are separated by a blank line
 * ("\n\n"). Each event may contain `event:`, `data:`, `id:`, `retry:` lines.
 * We only care about `event` and `data` here.
 *
 * Returns parsed events and the leftover buffer (an incomplete trailing event).
 */
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
        // SSE spec says a single leading space after the colon is stripped.
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
    // Returns true when the caller should treat this as stream termination.
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

    if (payload.type === 'done') {
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
                    await reader.cancel().catch(() => {
                        // ignore
                    });
                    return;
                }
            }
        }

        // Flush any trailing complete event (rare, if stream ended without a
        // final blank line).
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
    }
}

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
        <div class="flex h-full min-h-0 flex-1 flex-col gap-4 p-4">
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
                <Input v-model="state.input" :placeholder="t('chat.input_placeholder')" class="flex-1" :disabled="state.streaming" />
                <Button type="submit" :disabled="state.streaming || !state.input.trim()">{{ t('chat.send') }}</Button>
                <Button type="button" variant="secondary" @click="stopStream" :disabled="!state.streaming">
                    {{ t('chat.stop') }}
                </Button>
            </form>
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
