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
    <Head title="チーム招待 - アカウント作成" />

    <div class="min-h-screen flex items-center justify-center bg-background p-4">
        <Card class="w-full max-w-md">
            <CardHeader>
                <CardTitle class="text-2xl">チームへの招待</CardTitle>
                <CardDescription>
                    {{ inviter.name }} さんがあなたを <strong>{{ team.name }}</strong> チームに招待しています
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-6">
                <div v-if="team.description" class="p-3 bg-muted rounded-lg text-sm">
                    {{ team.description }}
                </div>

                <div class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        このチームに参加するには、アカウントを作成してください
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="email">メールアドレス</Label>
                        <Input
                            id="email"
                            type="email"
                            :model-value="email"
                            disabled
                            class="bg-muted"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">お名前</Label>
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
                        <Label for="password">パスワード</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">パスワード確認</Label>
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
                        アカウント作成してチームに参加
                    </Button>
                </form>

                <div class="text-center text-sm text-muted-foreground">
                    既にアカウントをお持ちですか？
                    <a href="/login" class="underline hover:text-foreground">
                        ログイン
                    </a>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
