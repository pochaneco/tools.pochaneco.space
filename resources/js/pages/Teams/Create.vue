<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const form = useForm({
  name: '',
  description: '',
});

function submit() {
  form.post('/teams');
}
</script>

<template>
  <AppLayout>
    <Head :title="t('teams.create')" />

    <div class="container mx-auto max-w-2xl p-4">
      <h1 class="text-2xl font-bold mb-6">{{ t('teams.create') }}</h1>

      <form @submit.prevent="submit" class="space-y-6">
        <div class="space-y-2">
          <Label for="name">{{ t('teams.name') }}</Label>
          <Input
            id="name"
            v-model="form.name"
            type="text"
            required
            :placeholder="t('teams.name')"
          />
          <InputError :message="form.errors.name" />
        </div>

        <div class="space-y-2">
          <Label for="description">{{ t('teams.description') }}</Label>
          <Textarea
            id="description"
            v-model="form.description"
            :placeholder="t('teams.description')"
          />
          <InputError :message="form.errors.description" />
        </div>

        <div class="flex justify-end gap-2">
          <Button
            type="submit"
            :disabled="form.processing"
          >
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin mr-2" />
            {{ t('teams.create') }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
