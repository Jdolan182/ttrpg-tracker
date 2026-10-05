<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Castle } from 'lucide-vue-next';

const props = defineProps<{
    token: string;
    campaign: { name: string; description: string | null; dm: string };
}>();

const isGuest = !usePage<SharedData>().props.auth.user;
const form = useForm({});
const join = () => form.post(route('campaigns.join.store', props.token));
</script>

<template>
    <Head :title="`Join ${campaign.name}`" />

    <AppLayout>
        <div class="mx-auto w-full max-w-lg p-4 pt-12">
            <div class="rounded-xl border border-border bg-card p-6 text-center shadow-sm">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <Castle class="size-7" />
                </div>
                <p class="text-sm text-muted-foreground">{{ campaign.dm }} invited you to</p>
                <h1 class="mt-1 text-2xl font-semibold">{{ campaign.name }}</h1>
                <p v-if="campaign.description" class="mt-3 whitespace-pre-line text-left text-sm text-muted-foreground">{{ campaign.description }}</p>

                <div class="mt-6 space-y-3">
                    <template v-if="isGuest">
                        <p class="text-sm">Log in or create a free account to join. You'll come straight back here.</p>
                        <div class="flex justify-center gap-2">
                            <Button variant="outline" as-child>
                                <Link :href="route('login')">Log in</Link>
                            </Button>
                            <Button as-child>
                                <Link :href="route('register')">Create an account</Link>
                            </Button>
                        </div>
                    </template>
                    <template v-else>
                        <Button class="w-full" :disabled="form.processing" @click="join">Join campaign</Button>
                        <InputError :message="form.errors.limit" />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
