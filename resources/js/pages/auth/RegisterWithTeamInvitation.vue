<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Props {
    token: string;
    email: string;
    team: {
        name: string;
        description?: string;
    };
    inviter: {
        name: string;
    };
}

const props = defineProps<Props>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(`/teams/invitations/${props.token}/register`);
};
</script>

<template>
    <Head :title="t('auth.team_invitation.title')" />

    <div class="min-h-screen flex items-center justify-center bg-background p-4">
        <Card class="w-full max-w-md">
            <CardHeader>
                <CardTitle class="text-2xl">{{ t('auth.team_invitation.heading') }}</CardTitle>
                <CardDescription>
                    <i18n-t keypath="auth.team_invitation.inviter_message" tag="span">
                        <template #inviter>{{ inviter.name }}</template>
                        <template #team><strong>{{ team.name }}</strong></template>
                    </i18n-t>
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-6">
                <div v-if="team.description" class="p-3 bg-muted rounded-lg text-sm">
                    {{ team.description }}
                </div>

                <div class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        {{ t('auth.team_invitation.description') }}
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="email">{{ t('auth.team_invitation.email') }}</Label>
                        <Input
                            id="email"
                            type="email"
                            :model-value="email"
                            disabled
                            class="bg-muted"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">{{ t('auth.team_invitation.name') }}</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">{{ t('auth.team_invitation.password') }}</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">{{ t('auth.team_invitation.password_confirmation') }}</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            required
                        />
                    </div>

                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="form.processing"
                    >
                        {{ t('auth.team_invitation.submit') }}
                    </Button>
                </form>

                <div class="text-center text-sm text-muted-foreground">
                    {{ t('auth.team_invitation.already_have_account') }}
                    <a href="/login" class="underline hover:text-foreground">
                        {{ t('auth.team_invitation.login') }}
                    </a>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
