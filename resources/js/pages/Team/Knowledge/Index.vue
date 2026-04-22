<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, FileText, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface StatusOption {
  value: string;
  label: string;
}

interface KnowledgeListItem {
  id: number;
  slug: string;
  title: string;
  status: string;
  author: { id: number; name: string } | null;
  published_at: string | null;
  updated_at: string | null;
}

const props = defineProps<{
  team: { id: string; name: string };
  knowledges: KnowledgeListItem[];
  filters: { status: string; q: string };
  statuses: StatusOption[];
  permissions: { can_create: boolean };
}>();

const statusFilter = ref(props.filters.status ?? '');
const searchTerm = ref(props.filters.q ?? '');

const hasItems = computed(() => props.knowledges.length > 0);

function applyFilters() {
  router.get(
    `/teams/${props.team.id}/knowledges`,
    { status: statusFilter.value || undefined, q: searchTerm.value || undefined },
    { preserveState: true, replace: true },
  );
}

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
</script>

<template>
  <AppLayout>
    <Head :title="t('knowledge.title')" />

    <div class="container mx-auto max-w-5xl p-4">
      <div class="flex justify-between items-center mb-6">
        <div>
          <h1 class="text-2xl font-bold">{{ t('knowledge.title') }}</h1>
          <p class="text-muted-foreground text-sm">{{ props.team.name }}</p>
        </div>
        <Button v-if="props.permissions.can_create" as-child>
          <Link :href="`/teams/${props.team.id}/knowledges/create`">
            <Plus class="h-4 w-4 mr-2" />
            {{ t('knowledge.create') }}
          </Link>
        </Button>
      </div>

      <div class="flex flex-col sm:flex-row gap-2 mb-6">
        <div class="relative flex-1">
          <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            v-model="searchTerm"
            type="text"
            :placeholder="t('knowledge.search_placeholder')"
            class="pl-9"
            @keyup.enter="applyFilters"
          />
        </div>
        <select
          v-model="statusFilter"
          class="h-9 rounded-md border border-input bg-background px-3 text-sm"
          @change="applyFilters"
        >
          <option value="">{{ t('knowledge.all_statuses') }}</option>
          <option v-for="s in props.statuses" :key="s.value" :value="s.value">
            {{ s.label }}
          </option>
        </select>
        <Button variant="outline" @click="applyFilters">{{ t('knowledge.apply_filters') }}</Button>
      </div>

      <div v-if="!hasItems" class="text-center py-12">
        <FileText class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
        <p class="text-lg text-muted-foreground mb-2">{{ t('knowledge.empty') }}</p>
        <p class="text-sm text-muted-foreground">{{ t('knowledge.empty_hint') }}</p>
      </div>

      <div v-else class="grid gap-3">
        <Card v-for="k in props.knowledges" :key="k.id" class="hover:shadow-md transition-shadow">
          <CardHeader class="pb-2">
            <div class="flex items-start justify-between gap-3">
              <CardTitle class="text-base">
                <Link :href="`/knowledges/${k.id}`" class="hover:underline">
                  {{ k.title }}
                </Link>
              </CardTitle>
              <span :class="['text-xs px-2 py-0.5 rounded', statusBadgeClass(k.status)]">
                {{ k.status }}
              </span>
            </div>
          </CardHeader>
          <CardContent class="text-xs text-muted-foreground flex gap-4">
            <span v-if="k.author">{{ t('knowledge.author') }}: {{ k.author.name }}</span>
            <span v-if="k.updated_at">{{ t('knowledge.updated_at') }}: {{ new Date(k.updated_at).toLocaleString() }}</span>
          </CardContent>
        </Card>
      </div>
    </div>
  </AppLayout>
</template>
