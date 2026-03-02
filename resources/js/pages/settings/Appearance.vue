<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { appearance } from '@/routes';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: t('settings.appearance.breadcrumb'),
        href: appearance().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="t('settings.appearance.breadcrumb')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="t('settings.appearance.heading')" :description="t('settings.appearance.description')" />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
