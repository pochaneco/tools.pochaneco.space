# 環境構築

```bash
# 環境変数
cp .env.example .env
# ORENO_EMAIL を設定

# Docker起動・インストール
./vendor/bin/sail up -d
./vendor/bin/sail composer install
./vendor/bin/sail npm install
./vendor/bin/sail artisan key:generate

# データベース
touch database/database.sqlite
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed

# 開発サーバー
./vendor/bin/sail npm run dev
```

ブラウザ: http://localhost
