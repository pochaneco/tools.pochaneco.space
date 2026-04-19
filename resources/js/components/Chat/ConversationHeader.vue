<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export type ConversationUsage = {
    prompt: number;
    completion: number;
    total: number;
};

const props = defineProps<{
    title: string | null;
    model: string | null;
    modelLabel?: string | null;
    usage: ConversationUsage | null;
}>();

const { t, locale } = useI18n();

// Locale-aware thousands separator for readability. Falls back silently
// to the plain number if `Intl` is unavailable (shouldn't happen in any
// modern browser, but keeps the template resilient).
function formatNumber(n: number): string {
    try {
        return new Intl.NumberFormat(locale.value).format(n);
    } catch {
        return String(n);
    }
}

const displayTitle = computed<string>(() => {
    const raw = (props.title ?? '').trim();
    return raw.length > 0 ? raw : t('chat.untitled');
});

/**
 * Short, user-friendly label for the conversation's default model. We
 * prefer the caller-provided label (from the app-level catalog) and fall
 * back to the raw model id so the header still renders if the catalog
 * hasn't been plumbed through for some reason.
 */
const displayModel = computed<string>(() => {
    if (props.modelLabel && props.modelLabel.length > 0) return props.modelLabel;
    return props.model ?? '';
});
</script>

<template>
    <header class="flex flex-col gap-1 border-b border-sidebar-border/70 pb-3 dark:border-sidebar-border" data-testid="chat-conversation-header">
        <h2 class="truncate text-base leading-tight font-semibold">
            {{ displayTitle }}
        </h2>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
            <span v-if="displayModel" class="font-mono" :title="model ?? undefined">
                {{ displayModel }}
            </span>
            <span v-if="displayModel && usage" aria-hidden="true" class="opacity-50">·</span>
            <span
                v-if="usage"
                class="inline-flex flex-wrap items-center gap-x-1"
                data-testid="chat-conversation-header-usage"
                :aria-label="t('chat.token_usage_label')"
            >
                <span>{{ t('chat.token_usage_prompt') }} {{ formatNumber(usage.prompt) }}</span>
                <span aria-hidden="true" class="opacity-50">/</span>
                <span>{{ t('chat.token_usage_completion') }} {{ formatNumber(usage.completion) }}</span>
                <span aria-hidden="true" class="opacity-50">/</span>
                <span>{{ t('chat.token_usage_total') }} {{ formatNumber(usage.total) }}</span>
                <span>{{ t('chat.tokens_unit') }}</span>
            </span>
        </div>
    </header>
</template>
