<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { toUrl, urlIsActive } from '@/lib/utils';
import { appearance } from '@/routes';
import { edit as editPassword } from '@/routes/password';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const sidebarNavItems: NavItem[] = [
    {
        title: t('settings.profile.title'),
        href: edit(),
    },
    {
        title: t('settings.password.title'),
        href: editPassword(),
    },
    {
        title: t('settings.two_factor.title'),
        href: show(),
    },
    {
        title: t('settings.appearance.title'),
        href: appearance(),
    },
];

const currentPath = typeof window !== undefined ? window.location.pathname : '';
</script>

<template>
    <div class="px-4 py-6">
        <Heading :title="t('settings.title')" :description="t('settings.description')" />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1 space-x-0">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': urlIsActive(item.href, currentPath) }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
