# デバッグ

```bash
# ログ
tail -f storage/logs/laravel.log
./vendor/bin/sail logs -f

# データベース
sqlite3 database/database.sqlite
./vendor/bin/sail mysql

# Tinker
./vendor/bin/sail artisan tinker
>>> User::count()
```

```vue
<!-- Vue -->
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
console.log('Props:', usePage().props);
</script>
```
