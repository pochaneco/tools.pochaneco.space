<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { LoaderCircle, Trash2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

const props = defineProps<{
  team: {
    id: string;
    name: string;
    description: string | null;
  };
  permissions: {
    can_delete: boolean;
  };
}>();

const form = useForm({
  name: props.team.name,
  description: props.team.description || '',
});

const deleteDialogOpen = ref(false);
const isDeleting = ref(false);

function submit() {
  form.put(`/teams/${props.team.id}`);
}

function deleteTeam() {
  isDeleting.value = true;
  router.delete(`/teams/${props.team.id}`, {
    onSuccess: () => {
      deleteDialogOpen.value = false;
    },
    onFinish: () => {
      isDeleting.value = false;
    },
  });
}
</script>

<template>
  <AppLayout>
    <Head :title="t('teams.edit')" />

    <div class="container mx-auto max-w-2xl p-4">
      <h1 class="text-2xl font-bold mb-6">{{ t('teams.edit') }}</h1>

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

        <div class="flex justify-between gap-2">
          <!-- Delete Button (Left) -->
          <Dialog v-model:open="deleteDialogOpen">
            <DialogTrigger as-child>
              <Button
                v-if="permissions.can_delete"
                type="button"
                variant="destructive"
              >
                <Trash2 class="h-4 w-4 mr-2" />
                {{ t('teams.delete') }}
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>{{ t('teams.confirm_delete_title') }}</DialogTitle>
                <DialogDescription>
                  {{ t('teams.confirm_delete_message', { name: team.name }) }}
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  @click="deleteDialogOpen = false"
                  :disabled="isDeleting"
                >
                  {{ t('common.cancel') }}
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  @click="deleteTeam"
                  :disabled="isDeleting"
                >
                  <LoaderCircle v-if="isDeleting" class="h-4 w-4 animate-spin mr-2" />
                  {{ t('teams.delete') }}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <!-- Save/Cancel Buttons (Right) -->
          <div class="flex gap-2">
            <Button
              type="button"
              variant="outline"
              as-child
            >
              <Link :href="`/teams/${team.id}`">
                {{ t('common.cancel') }}
              </Link>
            </Button>
            <Button
              type="submit"
              :disabled="form.processing"
            >
              <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin mr-2" />
              {{ t('common.save') }}
            </Button>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
