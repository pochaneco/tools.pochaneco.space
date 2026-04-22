<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import MarkdownMessage from '@/components/Chat/MarkdownMessage.vue';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Archive, CheckCircle2, XCircle, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Knowledge {
  id: number;
  team_id: string;
  slug: string;
  title: string;
  body: string;
  status: string;
  author: { id: number; name: string } | null;
  published_at: string | null;
  indexed_at: string | null;
  updated_at: string | null;
}

const props = defineProps<{
  knowledge: Knowledge;
  permissions: { can_update: boolean; can_delete: boolean };
  statuses: { value: string; label: string }[];
}>();

function statusBadgeClass(status: string): string {
  switch (status) {
    case 'published':
      return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
    case 'archived':
      return 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
    default:
      return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
  }
}

function publish() {
  router.post(`/knowledges/${props.knowledge.id}/publish`, {}, { preserveScroll: true });
}

function unpublish() {
  router.post(`/knowledges/${props.knowledge.id}/unpublish`, {}, { preserveScroll: true });
}

function archive() {
  router.post(`/knowledges/${props.knowledge.id}/archive`, {}, { preserveScroll: true });
}

function destroy() {
  if (!confirm(t('knowledge.delete_confirm'))) return;
  router.delete(`/knowledges/${props.knowledge.id}`);
}
</script>

<template>
  <AppLayout>
    <Head :title="props.knowledge.title" />

    <div class="container mx-auto max-w-4xl p-4">
      <div class="mb-6">
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <span :class="['text-xs px-2 py-0.5 rounded', statusBadgeClass(props.knowledge.status)]">
                {{ props.knowledge.status }}
              </span>
              <span v-if="props.knowledge.indexed_at" class="text-xs text-muted-foreground">
                {{ t('knowledge.indexed_at') }}: {{ new Date(props.knowledge.indexed_at).toLocaleString() }}
              </span>
            </div>
            <h1 class="text-2xl font-bold">{{ props.knowledge.title }}</h1>
            <p v-if="props.knowledge.author" class="text-muted-foreground text-sm mt-1">
              {{ t('knowledge.author') }}: {{ props.knowledge.author.name }}
            </p>
          </div>

          <div class="flex gap-2 flex-wrap justify-end">
            <Button
              v-if="props.permissions.can_update && props.knowledge.status !== 'published'"
              size="sm"
              variant="outline"
              @click="publish"
            >
              <CheckCircle2 class="h-4 w-4 mr-1" />
              {{ t('knowledge.publish') }}
            </Button>
            <Button
              v-if="props.permissions.can_update && props.knowledge.status === 'published'"
              size="sm"
              variant="outline"
              @click="unpublish"
            >
              <XCircle class="h-4 w-4 mr-1" />
              {{ t('knowledge.unpublish') }}
            </Button>
            <Button
              v-if="props.permissions.can_update && props.knowledge.status !== 'archived'"
              size="sm"
              variant="outline"
              @click="archive"
            >
              <Archive class="h-4 w-4 mr-1" />
              {{ t('knowledge.archive') }}
            </Button>
            <Button v-if="props.permissions.can_update" size="sm" as-child>
              <Link :href="`/knowledges/${props.knowledge.id}/edit`">
                <Pencil class="h-4 w-4 mr-1" />
                {{ t('knowledge.edit') }}
              </Link>
            </Button>
            <Button
              v-if="props.permissions.can_delete"
              size="sm"
              variant="destructive"
              @click="destroy"
            >
              <Trash2 class="h-4 w-4 mr-1" />
              {{ t('knowledge.delete') }}
            </Button>
          </div>
        </div>
      </div>

      <div class="rounded-md border border-input bg-background p-6">
        <MarkdownMessage :content="props.knowledge.body" />
      </div>

      <div class="mt-4 text-xs text-muted-foreground">
        <Link
          :href="`/teams/${props.knowledge.team_id}/knowledges`"
          class="hover:underline"
        >
          &larr; {{ t('knowledge.back_to_list') }}
        </Link>
      </div>
    </div>
  </AppLayout>
</template>
