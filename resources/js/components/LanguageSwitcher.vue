<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { setLocale } from '@/i18n';
import { Globe } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { locale } = useI18n();

const toggleLanguage = () => {
    const newLocale = locale.value === 'ja' ? 'en' : 'ja';
    setLocale(newLocale);
};
</script>

<template>
    <Button
        variant="ghost"
        size="sm"
        @click="toggleLanguage"
        class="flex items-center gap-2 text-sm"
        :title="locale === 'ja' ? 'Switch to English' : '日本語に切り替え'"
    >
        <Globe class="h-4 w-4" />
        <span class="hidden sm:inline">
            {{ locale === 'ja' ? 'EN' : '日本語' }}
        </span>
    </Button>
</template>
