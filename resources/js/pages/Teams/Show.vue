<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import InputError from '@/components/InputError.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, UserPlus, Trash2, LoaderCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { ref, watchEffect } from 'vue';

const { t } = useI18n();
const page = usePage();

// Flash message handling
const flashMessage = ref<string | null>(null);
const flashType = ref<'success' | 'error'>('success');

watchEffect(() => {
  if (page.props.success) {
    flashMessage.value = page.props.success as string;
    flashType.value = 'success';
    setTimeout(() => {
      flashMessage.value = null;
    }, 5000);
  } else if (page.props.error) {
    flashMessage.value = page.props.error as string;
    flashType.value = 'error';
    setTimeout(() => {
      flashMessage.value = null;
    }, 5000);
  }
});

interface TeamMember {
  id: number;
  name: string;
  email: string;
  role: string;
}

interface TeamOwner {
  id: number;
  name: string;
  email: string;
}

interface Team {
  id: string;
  name: string;
  description: string | null;
  owner: TeamOwner;
  members: TeamMember[];
}

interface Permissions {
  can_update: boolean;
  can_delete: boolean;
  can_invite: boolean;
  can_remove_member: boolean;
}

const props = defineProps<{
  team: Team;
  permissions: Permissions;
}>();

const addMemberDialogOpen = ref(false);
const editRoleDialogOpen = ref(false);
const editingMember = ref<TeamMember | null>(null);

const addMemberForm = useForm({
  email: '',
  role: 'member' as string,
});

const editRoleForm = useForm({
  role: 'member' as string,
});

function addMember() {
  addMemberForm.post(`/teams/${props.team.id}/members`, {
    onSuccess: () => {
      addMemberDialogOpen.value = false;
      addMemberForm.reset();
    },
  });
}

function openEditRoleDialog(member: TeamMember) {
  editingMember.value = member;
  editRoleForm.role = member.role;
  editRoleDialogOpen.value = true;
}

function updateMemberRole() {
  if (!editingMember.value) return;

  editRoleForm.patch(`/teams/${props.team.id}/members/${editingMember.value.id}`, {
    onSuccess: () => {
      editRoleDialogOpen.value = false;
      editingMember.value = null;
      editRoleForm.reset();
    },
  });
}

function removeMember(memberId: number) {
  if (confirm(t('teams.confirm_remove_member'))) {
    router.delete(`/teams/${props.team.id}/members/${memberId}`);
  }
}
</script>

<template>
  <AppLayout>
    <Head :title="team.name" />

    <div class="container mx-auto max-w-4xl p-4 space-y-6">
      <!-- Flash Message -->
      <div
        v-if="flashMessage"
        :class="[
          'p-4 rounded-lg border',
          flashType === 'success'
            ? 'bg-green-50 border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800 dark:text-green-200'
            : 'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-200'
        ]"
      >
        {{ flashMessage }}
      </div>

      <!-- Team Header Card -->
      <Card>
        <CardHeader>
          <div class="flex justify-between items-start">
            <div class="space-y-2">
              <CardTitle class="text-2xl">{{ team.name }}</CardTitle>
              <CardDescription v-if="team.description">
                {{ team.description }}
              </CardDescription>
              <p class="text-sm text-muted-foreground">
                {{ t('teams.owner') }}: {{ team.owner.name }} &lt;{{ team.owner.email }}&gt;
              </p>
            </div>
            <div class="flex gap-2">
              <Button v-if="permissions.can_update" variant="outline" size="sm" as-child>
                <Link :href="`/teams/${team.id}/edit`">
                  <Pencil class="h-4 w-4 mr-2" />
                  {{ t('teams.edit') }}
                </Link>
              </Button>
              <Dialog v-if="permissions.can_invite" v-model:open="addMemberDialogOpen">
                <DialogTrigger as-child>
                  <Button variant="outline" size="sm">
                    <UserPlus class="h-4 w-4 mr-2" />
                    {{ t('teams.invite_member') }}
                  </Button>
                </DialogTrigger>
                <DialogContent>
                  <form @submit.prevent="addMember">
                    <DialogHeader>
                      <DialogTitle>{{ t('teams.add_member_dialog_title') }}</DialogTitle>
                      <DialogDescription>
                        {{ t('teams.add_member_dialog_description') }}
                      </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-4">
                      <div class="grid gap-2">
                        <Label for="email">{{ t('teams.email_address') }}</Label>
                        <Input
                          id="email"
                          v-model="addMemberForm.email"
                          type="email"
                          placeholder="user@example.com"
                          required
                        />
                        <InputError :message="addMemberForm.errors.email" />
                      </div>

                      <div class="grid gap-2">
                        <Label for="role">{{ t('teams.select_role') }}</Label>
                        <select
                          id="role"
                          v-model="addMemberForm.role"
                          class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                          required
                        >
                          <option value="">{{ t('teams.select_role_placeholder') }}</option>
                          <option value="member">{{ t('teams.roles.member') }}</option>
                          <option value="owner">{{ t('teams.roles.owner') }}</option>
                        </select>
                        <InputError :message="addMemberForm.errors.role" />
                      </div>
                    </div>

                    <DialogFooter class="gap-2">
                      <DialogClose as-child>
                        <Button type="button" variant="secondary">
                          {{ t('common.cancel') }}
                        </Button>
                      </DialogClose>
                      <Button type="submit" :disabled="addMemberForm.processing">
                        <LoaderCircle v-if="addMemberForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                        {{ t('teams.add_member') }}
                      </Button>
                    </DialogFooter>
                  </form>
                </DialogContent>
              </Dialog>
            </div>
          </div>
        </CardHeader>
      </Card>

      <!-- Edit Role Dialog -->
      <Dialog v-model:open="editRoleDialogOpen">
        <DialogContent>
          <form @submit.prevent="updateMemberRole">
            <DialogHeader>
              <DialogTitle>{{ t('teams.change_member_role') }}</DialogTitle>
              <DialogDescription>
                {{ t('teams.change_member_role_description') }}
              </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-4">
              <div class="grid gap-2">
                <Label>{{ t('teams.email_address') }}</Label>
                <p class="text-sm text-muted-foreground">{{ editingMember?.email }}</p>
              </div>

              <div class="grid gap-2">
                <Label for="edit-role">{{ t('teams.select_role') }}</Label>
                <select
                  id="edit-role"
                  v-model="editRoleForm.role"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                  required
                >
                  <option value="member">{{ t('teams.roles.member') }}</option>
                  <option value="owner">{{ t('teams.roles.owner') }}</option>
                </select>
                <InputError :message="editRoleForm.errors.role" />
              </div>
            </div>

            <DialogFooter class="gap-2">
              <DialogClose as-child>
                <Button type="button" variant="secondary">
                  {{ t('common.cancel') }}
                </Button>
              </DialogClose>
              <Button type="submit" :disabled="editRoleForm.processing">
                <LoaderCircle v-if="editRoleForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ t('common.save') }}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <!-- Members Card -->
      <Card>
        <CardHeader>
          <CardTitle>{{ t('teams.members') }}</CardTitle>
          <CardDescription>
            {{ team.members.length }} {{ t('teams.members') }}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <div
              v-for="member in team.members"
              :key="member.id"
              class="flex justify-between items-center py-3 border-b last:border-b-0"
            >
              <div class="flex-1">
                <p class="font-medium">{{ member.name }}</p>
                <p class="text-sm text-muted-foreground">{{ member.email }}</p>
                <p class="text-xs text-muted-foreground mt-1">
                  {{ t(`teams.roles.${member.role.toLowerCase()}`) }}
                </p>
              </div>
              <div v-if="permissions.can_remove_member && member.id !== team.owner.id" class="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  @click="openEditRoleDialog(member)"
                >
                  <Pencil class="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  @click="removeMember(member.id)"
                >
                  <Trash2 class="h-4 w-4 text-destructive" />
                </Button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
