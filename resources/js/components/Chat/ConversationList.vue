<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

export type ConversationSummary = {
    id: number;
    title: string | null;
    model: string | null;
    updated_at: string | null;
    messages_count: number;
};

const props = defineProps<{
    conversations: ConversationSummary[];
    activeId: number | null;
}>();

const emit = defineEmits<{
    (e: 'select', id: number): void;
    (e: 'rename', id: number, newTitle: string): void;
    (e: 'delete', id: number): void;
    (e: 'new'): void;
}>();

const { t, locale } = useI18n();

const renamingId = ref<number | null>(null);
const renameValue = ref('');
const renameInputRef = ref<HTMLInputElement | null>(null);

// Ticking clock so relative timestamps stay fresh without a composable
// instance per row.
const nowTick = ref(Date.now());
let tickHandle: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    tickHandle = setInterval(() => {
        nowTick.value = Date.now();
    }, 30_000);
});
onUnmounted(() => {
    if (tickHandle !== null) {
        clearInterval(tickHandle);
        tickHandle = null;
    }
});

function startRename(c: ConversationSummary) {
    renamingId.value = c.id;
    renameValue.value = c.title ?? '';
    nextTick(() => {
        renameInputRef.value?.focus();
        renameInputRef.value?.select();
    });
}

function cancelRename() {
    renamingId.value = null;
    renameValue.value = '';
}

function commitRename() {
    if (renamingId.value === null) return;
    const title = renameValue.value.trim();
    if (!title) {
        cancelRename();
        return;
    }
    emit('rename', renamingId.value, title);
    cancelRename();
}

function onSelect(c: ConversationSummary) {
    if (renamingId.value === c.id) return;
    emit('select', c.id);
}

function onDelete(c: ConversationSummary) {
    emit('delete', c.id);
}

watch(
    () => props.conversations.map((c) => c.id).join(','),
    () => {
        if (renamingId.value !== null && !props.conversations.some((c) => c.id === renamingId.value)) {
            cancelRename();
        }
    },
);

function displayTitle(c: ConversationSummary): string {
    const raw = (c.title ?? '').trim();
    return raw.length > 0 ? raw : t('chat.untitled');
}

const isJa = computed(() => locale.value === 'ja');

function formatRelative(iso: string | null): string {
    if (!iso) return '';
    // depend on nowTick so this recomputes when the clock ticks
    const base = nowTick.value;
    const then = new Date(iso).getTime();
    const diffSec = Math.max(0, Math.round((base - then) / 1000));

    if (isJa.value) {
        if (diffSec < 45) return 'たった今';
        const mins = Math.round(diffSec / 60);
        if (mins < 60) return `${mins}分前`;
        const hours = Math.round(mins / 60);
        if (hours < 24) return `${hours}時間前`;
        const days = Math.round(hours / 24);
        if (days < 30) return `${days}日前`;
        return new Date(iso).toLocaleDateString('ja-JP');
    }

    if (diffSec < 45) return 'just now';
    const mins = Math.round(diffSec / 60);
    if (mins < 60) return mins === 1 ? '1 min ago' : `${mins} min ago`;
    const hours = Math.round(mins / 60);
    if (hours < 24) return hours === 1 ? '1 hour ago' : `${hours} hours ago`;
    const days = Math.round(hours / 24);
    if (days < 30) return days === 1 ? '1 day ago' : `${days} days ago`;
    return new Date(iso).toLocaleDateString();
}
</script>

<template>
    <div class="flex h-full flex-col bg-background">
        <div class="flex items-center justify-between gap-2 border-b p-3">
            <span class="text-sm font-semibold">{{ t('chat.conversation_list') }}</span>
            <Button size="sm" variant="default" @click="emit('new')">
                <Plus class="size-4" />
                <span>{{ t('chat.new_conversation') }}</span>
            </Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-2">
            <div v-if="conversations.length === 0" class="flex flex-col items-center gap-1 px-3 py-8 text-center text-sm text-muted-foreground">
                <span class="font-medium">{{ t('chat.empty_list') }}</span>
                <span class="text-xs">{{ t('chat.no_conversations_yet') }}</span>
            </div>

            <ul v-else class="flex flex-col gap-1">
                <li
                    v-for="c in conversations"
                    :key="c.id"
                    class="group relative flex items-center gap-1 rounded-md"
                    :class="[c.id === activeId ? 'bg-primary/10' : 'hover:bg-accent/60']"
                >
                    <template v-if="renamingId === c.id">
                        <form class="flex w-full items-center gap-1 p-1" @submit.prevent="commitRename">
                            <Input
                                ref="renameInputRef"
                                v-model="renameValue"
                                class="h-8 flex-1"
                                :placeholder="t('chat.rename_placeholder')"
                                @keydown.esc.prevent="cancelRename"
                            />
                            <Button type="submit" size="sm" variant="default">{{ t('chat.rename_save') }}</Button>
                            <Button type="button" size="sm" variant="ghost" @click="cancelRename">{{ t('chat.rename_cancel') }}</Button>
                        </form>
                    </template>
                    <template v-else>
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 flex-col items-start gap-0.5 rounded-md px-2 py-2 text-left"
                            :aria-current="c.id === activeId ? 'true' : undefined"
                            @click="onSelect(c)"
                        >
                            <span class="w-full truncate text-sm font-medium">{{ displayTitle(c) }}</span>
                            <span class="w-full truncate text-xs text-muted-foreground">{{ formatRelative(c.updated_at) }}</span>
                        </button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="mr-1 size-7 shrink-0 opacity-60 hover:opacity-100"
                                    :aria-label="t('common.actions')"
                                >
                                    <MoreHorizontal class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @select="startRename(c)">
                                    <Pencil class="size-4" />
                                    <span>{{ t('chat.rename') }}</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem variant="destructive" @select="onDelete(c)">
                                    <Trash2 class="size-4" />
                                    <span>{{ t('chat.delete') }}</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </li>
            </ul>
        </div>
    </div>
</template>
