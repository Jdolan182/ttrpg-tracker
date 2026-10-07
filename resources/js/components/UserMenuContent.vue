<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import type { SharedData, User } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, LogOut, MessageSquareHeart, Settings } from 'lucide-vue-next';

interface Props {
    user: User;
}

defineProps<Props>();

const { feedbackUrl, auth } = usePage<SharedData>().props;
const isAdmin = auth.isAdmin;
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('profile.edit')" as="button">
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="isAdmin" :as-child="true">
            <Link class="block w-full" :href="route('admin')" as="button">
                <ChartColumn class="mr-2 h-4 w-4" />
                Site stats
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="feedbackUrl" :as-child="true">
            <a class="block w-full" :href="feedbackUrl" target="_blank" rel="noopener">
                <MessageSquareHeart class="mr-2 h-4 w-4" />
                Send feedback
            </a>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" method="post" :href="route('logout')" as="button">
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
