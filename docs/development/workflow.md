# 開発ワークフロー

```bash
# 開発サーバー
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev

# マイグレーション
./vendor/bin/sail artisan make:migration create_xxx
./vendor/bin/sail artisan migrate

# テスト
./vendor/bin/sail artisan test

# ビルド
./vendor/bin/sail npm run build
./vendor/bin/sail npm run build:ssr  # 本番
```
