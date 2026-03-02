# Inertia.js 制約

## ❌ 使用不可
- Bladeテンプレート
- APIエンドポイント（JSON返却）

## ✅ 正しい実装

```php
// Controller
return Inertia::render('PageName', ['data' => $data]);
```

```vue
<!-- Vue Component -->
<script setup lang="ts">
interface Props { data: any; }
defineProps<Props>();
</script>
```

詳細: https://inertiajs.com/
