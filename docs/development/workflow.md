# 開発ワークフロー

## 開発サーバー
```bash
./vendor/bin/sail up -d                  # コンテナ起動
./vendor/bin/sail npm run dev            # Vite開発サーバー
./vendor/bin/sail composer run dev       # 全サービス一括起動（server, queue, logs, vite）
./vendor/bin/sail down                   # コンテナ停止
```

## Artisan
```bash
./vendor/bin/sail artisan make:model Xxx -mfc --no-interaction   # モデル+マイグレーション+ファクトリ+コントローラ
./vendor/bin/sail artisan make:controller XxxController --no-interaction
./vendor/bin/sail artisan make:request XxxRequest --no-interaction
./vendor/bin/sail artisan make:test XxxTest --pest --no-interaction
./vendor/bin/sail artisan make:test XxxTest --pest --unit --no-interaction
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan wayfinder:generate
```

## テスト
```bash
./vendor/bin/sail artisan test                              # 全テスト実行
./vendor/bin/sail artisan test tests/Feature/XxxTest.php    # ファイル指定
./vendor/bin/sail artisan test --filter=testName            # テスト名フィルタ
```

## コード品質
```bash
./vendor/bin/sail vendor/bin/pint --dirty    # PHP フォーマット（変更ファイルのみ）
./vendor/bin/sail vendor/bin/pint            # PHP フォーマット（全ファイル）
./vendor/bin/sail npm run lint               # ESLint（自動修正）
./vendor/bin/sail npm run format             # Prettier（自動修正）
./vendor/bin/sail npm run format:check       # Prettier（チェックのみ）
```

## ビルド
```bash
./vendor/bin/sail npm run build              # フロントエンドビルド
./vendor/bin/sail npm run build:ssr          # SSRビルド
```

## デプロイ
```bash
./vendor/bin/sail vendor/bin/dep deploy      # 本番デプロイ
```
