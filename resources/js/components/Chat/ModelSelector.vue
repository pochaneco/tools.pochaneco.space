<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Check, ChevronDown, Cpu } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

export type ModelInfo = {
    label: string;
    description: string;
};

export type ModelMap = Record<string, ModelInfo>;

const props = defineProps<{
    modelValue: string;
    models: ModelMap;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
}>();

const { t } = useI18n();

const entries = computed<Array<[string, ModelInfo]>>(() => Object.entries(props.models));

const currentLabel = computed<string>(() => {
    const meta = props.models[props.modelValue];
    if (meta) return meta.label;
    // Graceful fallback when the selected id isn't in the catalog (old
    // data, removed model, etc.) — show the raw id so the user at least
    // sees *something* instead of an empty button.
    return props.modelValue || '—';
});

function choose(id: string) {
    if (id === props.modelValue) return;
    emit('update:modelValue', id);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled"
                :aria-label="t('chat.model_selector_aria', { label: currentLabel })"
                class="h-9 gap-1.5 px-2.5 text-xs"
            >
                <Cpu class="size-3.5" aria-hidden="true" />
                <span class="font-mono">{{ currentLabel }}</span>
                <ChevronDown class="size-3.5 opacity-60" aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="min-w-[18rem]">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ t('chat.model') }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="[id, meta] in entries"
                :key="id"
                class="flex items-start gap-2 py-2"
                :aria-checked="id === props.modelValue"
                @select="choose(id)"
            >
                <Check class="mt-0.5 size-4 shrink-0" :class="id === props.modelValue ? 'opacity-100' : 'opacity-0'" aria-hidden="true" />
                <div class="flex min-w-0 flex-col">
                    <span class="text-sm font-medium">{{ meta.label }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{ meta.description }}</span>
                    <span class="truncate font-mono text-[10px] text-muted-foreground/70">{{ id }}</span>
                </div>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
