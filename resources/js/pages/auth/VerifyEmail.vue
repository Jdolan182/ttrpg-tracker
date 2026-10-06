<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{
    status?: string;
}>();

const email = usePage<SharedData>().props.auth.user?.email;

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout
        title="Check your email"
        :description="`We sent a link to ${email ?? 'your email address'}. Click it to finish setting up your account.`"
    >
        <Head title="Verify your email" />

        <div v-if="status === 'verification-link-sent'" class="mb-4 text-center text-sm font-medium text-green-600">
            A new link is on its way. It can take a minute; check your spam folder if it doesn't turn up.
        </div>

        <p class="mb-6 text-center text-sm text-muted-foreground">
            Saving encounters, making creatures and joining campaigns need a verified email. You can use the tracker in the meantime.
        </p>

        <form @submit.prevent="submit" class="space-y-6 text-center">
            <div class="flex flex-wrap justify-center gap-2">
                <Button :disabled="form.processing" variant="secondary">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Send the link again
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="route('encounters.index')">Go to the tracker</Link>
                </Button>
            </div>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm"> Log out </TextLink>
        </form>
    </AuthLayout>
</template>
