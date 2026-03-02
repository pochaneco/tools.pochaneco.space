<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Users, Crown } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Team {
  id: string;
  name: string;
  description: string | null;
  owner: {
    id: number;
    name: string;
  };
  members_count: number;
  role: string;
  is_owner: boolean;
  created_at: string;
}

const props = defineProps<{
  teams: Team[];
}>();
</script>

<template>
  <AppLayout>
    <Head :title="t('teams.title')" />

    <div class="container mx-auto max-w-6xl p-4">
      <div class="flex justify-between items-center mb-6">
        <div>
          <h1 class="text-2xl font-bold">{{ t('teams.title') }}</h1>
          <p class="text-muted-foreground">{{ props.teams.length }} {{ t('teams.members') }}</p>
        </div>
        <Button as-child>
          <Link :href="`/teams/create`">
            <Plus class="h-4 w-4 mr-2" />
            {{ t('teams.create') }}
          </Link>
        </Button>
      </div>

      <div v-if="props.teams.length === 0" class="text-center py-12">
        <Users class="h-12 w-12 mx-auto text-muted-foreground mb-4" />
        <p class="text-lg text-muted-foreground mb-2">{{ t('teams.no_teams') }}</p>
        <p class="text-sm text-muted-foreground mb-4">{{ t('teams.create_first_team') }}</p>
        <Button as-child>
          <Link :href="`/teams/create`">
            <Plus class="h-4 w-4 mr-2" />
            {{ t('teams.create') }}
          </Link>
        </Button>
      </div>

      <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Card
          v-for="team in props.teams"
          :key="team.id"
          class="hover:shadow-lg transition-shadow cursor-pointer"
        >
          <CardHeader>
            <div class="flex justify-between items-start">
              <div class="flex-1">
                <CardTitle class="flex items-center gap-2">
                  <Crown v-if="team.is_owner" class="h-4 w-4 text-yellow-500" />
                  {{ team.name }}
                </CardTitle>
                <CardDescription v-if="team.description" class="mt-2">
                  {{ team.description }}
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div class="space-y-2">
              <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <Users class="h-4 w-4" />
                <span>{{ team.members_count }} {{ t('teams.members') }}</span>
              </div>
              <div class="text-xs text-muted-foreground">
                {{ t('teams.owner') }}: {{ team.owner.name }}
              </div>
              <Button variant="outline" size="sm" class="w-full mt-4" as-child>
                <Link :href="`/teams/${team.id}`">
                  {{ t('teams.view') }}
                </Link>
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  </AppLayout>
</template>
