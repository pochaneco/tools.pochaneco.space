# トラブルシューティング

## Inertia page not found
- `resources/js/pages/` にファイルが存在するか確認
- `Inertia::render('PageName')` のパスが正しいか確認

## hasRole() undefined
- `app/Models/User.php` にメソッドが定義されているか確認

## Table already exists
```bash
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan migrate:rollback
```

## ポート使用中
```bash
# .env
APP_PORT=8000
./vendor/bin/sail down && ./vendor/bin/sail up -d
```

## 権限エラー
```bash
sudo chown -R $USER:$USER storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```
