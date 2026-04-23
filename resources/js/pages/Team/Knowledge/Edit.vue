<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import MarkdownMessage from '@/components/Chat/MarkdownMessage.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface StatusOption {
  value: string;
  label: string;
}

interface Knowledge {
  id: number;
  slug: string;
  title: string;
  body: string;
  status: string;
}

const props = defineProps<{
  team: { id: string; name: string };
  knowledge: Knowledge | null;
  statuses: StatusOption[];
}>();

const isEdit = computed(() => props.knowledge !== null);

const form = useForm({
  title: props.knowledge?.title ?? '',
  body: props.knowledge?.body ?? '',
  status: props.knowledge?.status ?? 'draft',
});

function submit() {
  if (isEdit.value) {
    form.patch(`/knowledges/${props.knowledge!.id}`);
  } else {
    form.post(`/teams/${props.team.id}/knowledges`);
  }
}
</script>

<template>
  <AppLayout>
    <Head :title="isEdit ? t('knowledge.edit') : t('knowledge.create')" />

    <div class="container mx-auto max-w-5xl p-4">
      <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ isEdit ? t('knowledge.edit') : t('knowledge.create') }}</h1>
        <p class="text-muted-foreground text-sm">{{ props.team.name }}</p>
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        <div class="space-y-2">
          <Label for="title">{{ t('knowledge.field_title') }}</Label>
          <Input
            id="title"
            v-model="form.title"
            type="text"
            required
            maxlength="200"
            :placeholder="t('knowledge.field_title')"
          />
          <InputError :message="form.errors.title" />
        </div>

        <div class="grid md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label for="body">{{ t('knowledge.field_body') }}</Label>
            <Textarea
              id="body"
              v-model="form.body"
              rows="20"
              required
              class="font-mono text-sm"
              :placeholder="t('knowledge.body_placeholder')"
            />
            <InputError :message="form.errors.body" />
          </div>

          <div class="space-y-2">
            <Label>{{ t('knowledge.preview') }}</Label>
            <div class="rounded-md border border-input bg-background p-4 min-h-[480px] overflow-auto">
              <MarkdownMessage v-if="form.body" :content="form.body" />
              <p v-else class="text-muted-foreground text-sm">{{ t('knowledge.preview_empty') }}</p>
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <Label for="status">{{ t('knowledge.field_status') }}</Label>
          <select
            id="status"
            v-model="form.status"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
          >
            <option v-for="s in props.statuses" :key="s.value" :value="s.value">
              {{ s.label }}
            </option>
          </select>
          <InputError :message="form.errors.status" />
          <p class="text-xs text-muted-foreground">{{ t('knowledge.status_hint') }}</p>
        </div>

        <div class="flex justify-end gap-2">
          <Button type="submit" :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin mr-2" />
            {{ isEdit ? t('knowledge.save') : t('knowledge.create') }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
