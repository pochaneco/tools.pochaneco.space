<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

// Components
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

const passwordInput = ref<InstanceType<typeof Input> | null>(null);
const { t } = useI18n();
</script>

<template>
    <div class="space-y-6">
        <HeadingSmall :title="t('settings.profile.delete_account.title')" :description="t('settings.profile.delete_account.description')" />
        <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">{{ t('settings.profile.delete_account.warning_title') }}</p>
                <p class="text-sm">{{ t('settings.profile.delete_account.warning_message') }}</p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="delete-user-button">Delete account</Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.$el?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                            <DialogHeader class="space-y-3">
                                <DialogTitle>{{ t('settings.profile.delete_account.confirm_title') }}</DialogTitle>
                                <DialogDescription>
                                    {{ t('settings.profile.delete_account.confirm_description') }}
                                </DialogDescription>
                            </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">{{ t('settings.profile.delete_account.password_label') }}</Label>
                            <Input id="password" type="password" name="password" ref="passwordInput" :placeholder="t('settings.profile.delete_account.password_placeholder')" />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ t('settings.profile.delete_account.cancel') }}
                                </Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" :disabled="processing" data-test="confirm-delete-user-button"> {{ t('settings.profile.delete_account.confirm_button') }} </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
