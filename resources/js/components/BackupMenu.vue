<script setup lang="ts">
// "Backup" menu: download everything you've made, or import a backup file. The result shows in a
// BackupNotice on the same page.
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/vue3';
import { ChevronDown, Download, HardDriveDownload, Upload } from 'lucide-vue-next';
import { ref } from 'vue';

const fileInput = ref<HTMLInputElement>();
const importing = ref(false);

const pickFile = () => fileInput.value?.click();

const importFile = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    router.post(
        route('backup.import'),
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => (importing.value = true),
            onFinish: () => (importing.value = false),
        },
    );
};
</script>

<template>
    <div class="contents">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="sm" :disabled="importing">
                    <HardDriveDownload />
                    {{ importing ? 'Importing…' : 'Backup' }}
                    <ChevronDown class="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-64">
                <DropdownMenuItem as-child>
                    <a :href="route('backup.export')" download>
                        <Download />
                        <span>
                            Export everything
                            <span class="block text-xs text-muted-foreground">Your creatures and saved encounters, as a file</span>
                        </span>
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem @select="pickFile">
                    <Upload />
                    <span>
                        Import a backup…
                        <span class="block text-xs text-muted-foreground">Adds to what you have; nothing is replaced</span>
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <input ref="fileInput" type="file" accept=".json,application/json" class="hidden" @change="importFile" />
    </div>
</template>
