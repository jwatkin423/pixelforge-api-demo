<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, History, Sparkles, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/app/AppLogo.vue';
import NavMain from '@/components/nav/NavMain.vue';
import NavUser from '@/components/nav/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { formatUsd } from '@/lib/money';
import { usage } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { create, index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: create(), icon: Sparkles },
    { title: 'History', href: history(), icon: History },
    {
        title: `Credits · ${formatUsd(page.props.auth.user.balance_nanos)}`,
        href: credits(),
        icon: Wallet,
    },
    ...(page.props.auth.user.is_admin
        ? [{ title: 'Usage report', href: usage(), icon: ChartColumn }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="create()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
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
