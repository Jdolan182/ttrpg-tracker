<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const passwordInput = ref<InstanceType<typeof Input> | null>(null);

const form = useForm({
    password: '',
});

// What goes with the account, from the usage counts every page gets, so nobody's surprised.
const limits = computed(() => usePage<SharedData>().props.limits);
const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;
const losses = computed(() => {
    const l = limits.value;
    if (!l) return [];
    return [
        l.creatures.used ? `${plural(l.creatures.used, 'creature or character', 'creatures and characters')} you made` : null,
        l.encounters.used ? plural(l.encounters.used, 'saved encounter', 'saved encounters') : null,
        l.campaigns.used
            ? `${plural(l.campaigns.used, 'campaign', 'campaigns')} you run, along with ${l.campaigns.used === 1 ? 'its' : 'their'} party. Your players lose access too.`
            : null,
        l.campaigns_joined.used ? `your place in ${plural(l.campaigns_joined.used, 'campaign', 'campaigns')} you've joined` : null,
    ].filter((line): line is string => !!line);
});

const deleteUser = (e: Event) => {
    e.preventDefault();

    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => (passwordInput.value?.$el as HTMLInputElement | undefined)?.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    form.clearErrors();
    form.reset();
};
</script>

<template>
    <div class="space-y-6">
        <HeadingSmall title="Delete account" description="Delete your account and everything you've made" />
        <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Warning</p>
                <p class="text-sm">This can't be undone. Export a backup first (Encounters → Backup) if you might want your things back.</p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive">Delete account</Button>
                </DialogTrigger>
                <DialogContent>
                    <form class="space-y-6" @submit="deleteUser">
                        <DialogHeader class="space-y-3">
                            <DialogTitle>Delete your account?</DialogTitle>
                            <DialogDescription as="div" class="space-y-2">
                                <p>Everything you've made is deleted with it, for good:</p>
                                <ul v-if="losses.length" class="list-disc space-y-1 pl-5">
                                    <li v-for="line in losses" :key="line">{{ line }}</li>
                                </ul>
                                <p v-else>You haven't made anything yet, so only the account itself goes.</p>
                                <p>Enter your password to confirm.</p>
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">Password</Label>
                            <Input id="password" ref="passwordInput" v-model="form.password" type="password" name="password" placeholder="Password" />
                            <InputError :message="form.errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="secondary" @click="closeModal">Cancel</Button>
                            </DialogClose>
                            <Button type="submit" variant="destructive" :disabled="form.processing">Delete account</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
