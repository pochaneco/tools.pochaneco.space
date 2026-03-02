<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, router } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Team {
  id: string;
  name: string;
  description: string | null;
}

interface Inviter {
  name: string;
  email: string;
}

interface Invitation {
  id: number;
  email: string;
  role: string;
  expires_at: string;
  team: Team;
  inviter: Inviter;
  token?: string;
}

const props = defineProps<{
  invitation: Invitation;
}>();

function acceptInvitation() {
  router.post(`/teams/invitations/${props.invitation.token}/accept`);
}

function declineInvitation() {
  if (confirm(t('teams.confirm_decline_invitation'))) {
    router.post(`/teams/invitations/${props.invitation.token}/decline`);
  }
}
</script>

<template>
  <AppLayout>
    <Head :title="t('teams.invitation_details')" />

    <div class="container mx-auto max-w-2xl p-4 space-y-6">
      <Card>
        <CardHeader>
          <div class="flex items-center gap-2">
            <UserPlus class="h-6 w-6 text-primary" />
            <CardTitle class="text-2xl">{{ t('teams.invitation_details') }}</CardTitle>
          </div>
          <CardDescription>
            {{ t('teams.invited_by') }}: {{ invitation.inviter.name }} &lt;{{ invitation.inviter.email }}&gt;
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-6">
          <div class="space-y-4">
            <div>
              <h3 class="font-semibold text-lg">{{ invitation.team.name }}</h3>
              <p v-if="invitation.team.description" class="text-sm text-muted-foreground mt-1">
                {{ invitation.team.description }}
              </p>
            </div>

            <div class="space-y-2">
              <div class="flex justify-between text-sm">
                <span class="text-muted-foreground">{{ t('teams.email_address') }}:</span>
                <span class="font-medium">{{ invitation.email }}</span>
              </div>
              <div class="flex justify-between text-sm">
                <span class="text-muted-foreground">{{ t('teams.role') }}:</span>
                <span class="font-medium">{{ t(`teams.roles.${invitation.role}`) }}</span>
              </div>
            </div>
          </div>

          <div class="flex gap-3">
            <Button @click="acceptInvitation" class="flex-1">
              {{ t('teams.accept_invitation') }}
            </Button>
            <Button @click="declineInvitation" variant="outline" class="flex-1">
              {{ t('teams.decline_invitation') }}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
