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

type Message = { id: string; text: string; ts: string; role: 'user' | 'assistant' };

const state = reactive({
    messages: [] as Message[],
    input: '',
    connected: false,
    done: false,
    error: '' as string | null,
});

let es: EventSource | null = null;
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

function startStream() {
    if (state.connected) return;
    state.error = null;
    state.done = false;
    // サーバ側のデフォルト（ChatController の定数）を利用する
    const url = chatRoutes.stream.url();
    es = new EventSource(url);
    state.connected = true;

    es.addEventListener('message', (evt) => {
        try {
            const data = JSON.parse((evt as MessageEvent).data);
            state.messages.push({ ...data, role: 'assistant' });
            scrollToBottom();
        } catch {
            // ignore parse errors
        }
    });

    es.addEventListener('end', () => {
        state.done = true;
        stopStream();
    });

    es.addEventListener('finished', () => {
        state.done = true;
        stopStream();
    });

    es.onerror = () => {
        state.error = t('chat.connection_error');
        stopStream();
    };
}

function stopStream() {
    if (es) {
        es.close();
        es = null;
    }
    state.connected = false;
}

function send() {
    if (!state.input.trim()) return;
    state.messages.push({ id: genId(), text: state.input.trim(), ts: new Date().toISOString(), role: 'user' });
    state.input = '';
    scrollToBottom();
    startStream();
}

onBeforeUnmount(() => {
    stopStream();
});
</script>

<template>
    <Head :title="t('chat.title')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full min-h-0 flex-1 flex-col gap-4 p-4">
            <div ref="scrollEl" class="min-h-0 flex-1 overflow-auto rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <div class="flex flex-col gap-3">
                    <div
                        v-for="m in state.messages"
                        :key="m.id"
                        class="max-w-[80%] rounded-md px-3 py-2 text-sm"
                        :class="m.role === 'user' ? 'self-end bg-primary/10' : 'self-start bg-muted'"
                    >
                        <div class="text-xs opacity-70">{{ new Date(m.ts).toLocaleTimeString() }} · {{ m.role === 'user' ? t('chat.user') : t('chat.assistant') }}</div>
                        <div class="whitespace-pre-wrap">{{ m.text }}</div>
                    </div>
                    <div v-if="state.connected && !state.done" class="self-start text-xs opacity-70">{{ t('common.loading') }}</div>
                    <div v-if="state.done" class="self-start text-xs opacity-70">{{ t('chat.stream_ended') }}</div>
                    <div v-if="state.error" class="self-start text-xs text-red-600">{{ state.error }}</div>
                </div>
            </div>

            <form class="flex gap-2" @submit.prevent="send">
                <Input v-model="state.input" :placeholder="t('chat.input_placeholder')" class="flex-1" />
                <Button type="submit" :disabled="state.connected">{{ t('chat.send') }}</Button>
                <Button type="button" variant="secondary" @click="stopStream" :disabled="!state.connected">{{ t('chat.stop') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped></style>
