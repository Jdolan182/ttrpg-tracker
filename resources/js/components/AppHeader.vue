<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import type { NavItem, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, Castle, Menu, MessageSquareHeart, ScrollText, Swords } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const auth = computed(() => page.props.auth);

// Active when on the item's page or any page beneath it, e.g. /compendium/goblin.
const isCurrentRoute = (url: string) => {
    const path = page.url.split('?')[0];
    return url === '/' ? path === '/' : path === url || path.startsWith(`${url}/`);
};

const activeItemStyles = computed(() => (url: string) => (isCurrentRoute(url) ? 'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : ''));

// Saved encounters and campaigns need an account, so guests don't see those links.
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Tracker',
        href: '/',
        icon: Swords,
    },
    ...(auth.value.user ? [{ title: 'Encounters', href: '/encounters', icon: ScrollText }] : []),
    {
        title: 'Compendium',
        href: '/compendium',
        icon: BookOpen,
    },
    ...(auth.value.user ? [{ title: 'Campaigns', href: '/campaigns', icon: Castle }] : []),
]);
</script>

<template>
    <div>
        <div class="border-b border-sidebar-border/80">
            <div class="mx-auto flex h-16 items-center px-4 md:max-w-7xl">
                <!-- Mobile Menu -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger :as-child="true">
                            <Button variant="ghost" size="icon" class="mr-2 h-9 w-9">
                                <Menu class="h-5 w-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-[300px] p-6">
                            <SheetTitle class="sr-only">Navigation Menu</SheetTitle>
                            <SheetHeader class="flex justify-start text-left">
                                <AppLogo name-from="always" />
                            </SheetHeader>
                            <div class="flex h-full flex-1 flex-col justify-between space-y-4 py-6">
                                <nav class="-mx-3 space-y-1">
                                    <Link
                                        v-for="item in mainNavItems"
                                        :key="item.title"
                                        :href="item.href"
                                        class="flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent"
                                        :class="activeItemStyles(item.href)"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                        {{ item.title }}
                                    </Link>
                                </nav>
                                <!-- On phones "Log in" lives here, so the header has room -->
                                <div v-if="!auth.user" class="flex flex-col gap-2 sm:hidden">
                                    <Button variant="outline" as-child>
                                        <Link :href="route('login')">Log in</Link>
                                    </Button>
                                    <Button as-child>
                                        <Link :href="route('register')">Create a free account</Link>
                                    </Button>
                                </div>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <Link :href="route('encounters.index')" class="flex min-w-0 items-center" :aria-label="`${page.props.appName} home`">
                    <AppLogo />
                </Link>

                <!-- Desktop Menu -->
                <div class="hidden h-full lg:flex lg:flex-1">
                    <NavigationMenu class="ml-10 flex h-full items-stretch">
                        <NavigationMenuList class="flex h-full items-stretch space-x-2">
                            <NavigationMenuItem v-for="(item, index) in mainNavItems" :key="index" class="relative flex h-full items-center">
                                <Link :href="item.href">
                                    <NavigationMenuLink
                                        :class="[navigationMenuTriggerStyle(), activeItemStyles(item.href), 'h-9 cursor-pointer px-3']"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                                        {{ item.title }}
                                    </NavigationMenuLink>
                                </Link>
                                <div v-if="isCurrentRoute(item.href)" class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-primary"></div>
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <!-- Up here so testers always see it, not just at the bottom of a long page -->
                    <Button v-if="page.props.feedbackUrl" variant="ghost" size="sm" class="h-9 px-2 md:px-3" as-child>
                        <a :href="page.props.feedbackUrl" target="_blank" rel="noopener" title="Send feedback">
                            <MessageSquareHeart class="size-5 md:size-4" />
                            <span class="hidden md:inline">Feedback</span>
                            <span class="sr-only md:hidden">Send feedback</span>
                        </a>
                    </Button>
                    <ThemeToggle />
                    <template v-if="!auth.user">
                        <Button variant="ghost" size="sm" class="hidden sm:inline-flex" as-child>
                            <Link :href="route('login')">Log in</Link>
                        </Button>
                        <Button size="sm" as-child>
                            <Link :href="route('register')">Register</Link>
                        </Button>
                    </template>

                    <DropdownMenu v-else>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                            >
                                <Avatar class="size-8 overflow-hidden rounded-full">
                                    <AvatarImage v-if="auth.user.avatar" :src="auth.user.avatar" :alt="auth.user.name" />
                                    <AvatarFallback class="rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white">
                                        {{ getInitials(auth.user?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>
    </div>
</template>
