<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import chatRoutes from '@/routes/chat';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { LayoutGrid, MessageSquare, Users } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import AppLogo from './AppLogo.vue';

const { t } = useI18n();

const mainNavItems: NavItem[] = [
    {
        title: t('dashboard.title'),
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: t('chat.title'),
        href: chatRoutes.index().url,
        icon: MessageSquare,
    },
    {
        title: t('teams.title'),
        href: '/teams',
        icon: Users,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="p-1">
            <Link :href="dashboard()" class="block w-full">
                <AppLogo />
            </Link>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
