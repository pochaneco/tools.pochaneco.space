# 実装パターン

## 新しいページ

```php
// Controller
return Inertia::render('MyPage', ['items' => $items]);
```

```vue
<!-- resources/js/pages/MyPage.vue -->
<script setup lang="ts">
interface Props { items: any[]; }
defineProps<Props>();
</script>
```

```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::get('/mypage', [MyPageController::class, 'index']);
});
```

## ロール制限

```php
// Middleware
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', [AdminController::class, 'index']);
});

// Controller内
if ($user->isAdmin()) { }
if ($user->hasRole(UserRole::VIP)) { }
```

## フォーム

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
const form = useForm({ name: '' });
const submit = () => form.post('/api/submit');
</script>

<template>
  <form @submit.prevent="submit">
    <input v-model="form.name" />
    <span v-if="form.errors.name">{{ form.errors.name }}</span>
  </form>
</template>
```
