# デプロイ

本プロジェクトは **[Deployer](https://deployer.org/) v7** を利用し、さくらインターネットのレンタルサーバへデプロイする。設定ファイルは [deploy.php](../../deploy.php)。

## 前提

- デプロイ実行マシンに **PHP 8.2+** が入っていること (ホスト側の PHP でよい、Sail 内からは実行しない)
- `~/.ssh/config` に本番サーバへの SSH 接続情報が設定済みで、公開鍵ログインできること
- リポジトリがクリーンな状態 (コミット漏れなし) で、`main` ブランチが最新
- `.env.production` がローカルに存在し、本番向けの環境変数が設定されている

## SSH 接続先

| 項目 | 値 |
|---|---|
| ホスト | `<DEPLOY_HOST>` |
| ユーザー | `pochaneco` |
| デプロイ先パス | `<DEPLOY_PATH>` |
| リポジトリ | `git@github.com:pochaneco/tools.pochaneco.space.git` |

## デプロイコマンド

ホスト (Sail 外) で以下を実行:

```bash
DEPLOY_HOST=<DEPLOY_HOST> \
DEPLOY_USER=<DEPLOY_USER> \
DEPLOY_PATH='<DEPLOY_PATH>' \
php vendor/bin/dep deploy
```

環境変数を毎回指定しない運用にしたい場合は `direnv` や `~/.zshrc` で export しておく。

## Deployer が行うこと (タスク順)

1. **`deploy:info`** 接続確認・リリース番号採番
2. **`deploy:setup`** 初回のみディレクトリ構造を作成
3. **`deploy:lock`** 重複デプロイ防止ロック取得
4. **`deploy:release`** `releases/<N>` ディレクトリ作成
5. **`deploy:update_code`** Git から clone (最新の `main` 指定コミット)
6. **`deploy:env`** / **`deploy:shared`** `shared/.env` 等を symlink
7. **`deploy:writable`** `storage/` / `bootstrap/cache/` に書き込み権限付与
8. **`deploy:vendors`** サーバ上で `composer install --no-dev`
9. **`artisan storage:link`** / **`config:cache`** / **`route:cache`** / **`view:cache`** / **`event:cache`**
10. **`artisan migrate`** マイグレーション自動適用
11. **`local:warn-dirty`** / **`local:warn-branch`** ローカルの git 状態を警告
12. **`assets:build`** (ローカル) `vendor/bin/sail npm ci && npm run build` — サーバに Node を置かない方針
13. **`assets:upload`** (rsync) `public/build/` を `releases/<N>/public/build` へアップロード
14. **`deploy:symlink`** `current -> releases/<N>` を原子的に切替 (本番トラフィックが新リリースへ)
15. **`deploy:unlock`** / **`deploy:cleanup`** ロック解除 + 古いリリース削除

## 初回セットアップ (本番側 `shared/.env`)

サーバの `shared/.env` に本番向けの環境変数を配置する必要がある。ローカルの `.env.production` をアップロードするヘルパータスクを用意:

```bash
DEPLOY_HOST=<DEPLOY_HOST> \
DEPLOY_USER=<DEPLOY_USER> \
DEPLOY_PATH='<DEPLOY_PATH>' \
php vendor/bin/dep env:push
```

このタスクは `.env.production` を `<deploy_path>/shared/.env` にアップロードし、`chmod 600` する。**本番 API キー等を変更した後は `env:push` → `deploy` の順で実行**。

## ロールバック

直前のリリースに戻す:

```bash
DEPLOY_HOST=<DEPLOY_HOST> \
DEPLOY_USER=<DEPLOY_USER> \
DEPLOY_PATH='<DEPLOY_PATH>' \
php vendor/bin/dep rollback
```

`current` symlink が一つ前の `releases/<N-1>` を指し直す。マイグレーションは自動ロールバックされないので、スキーマ変更が絡む場合は手動で `artisan migrate:rollback` も必要。

## トラブルシューティング

### `assets:build` で `npm ci` が `EACCES` で失敗

ローカルの `node_modules` に **root 所有のファイル** が混ざっている可能性 (過去に `docker exec -u root` で生成されたもの)。以下で所有権を戻す:

```bash
docker exec -u root toolspochanecospace-laravel.test-1 \
  chown -R sail:sail /var/www/html/node_modules /var/www/html/vendor
```

### `deploy:info` で `Host key verification failed`

Sail コンテナから実行しているのが原因。SSH 鍵と `known_hosts` はホスト側にあるため **ホストの PHP で直接 `php vendor/bin/dep deploy` を実行** する。

### 古い `public/build` が残っている

`assets:upload` は事前に `rm -rf {{release_path}}/public/build` してから rsync するので、サーバ側の古いアセットは自動で消える。万一 stale なら releaseディレクトリごと入れ替わるのを待つ (`current` symlink 切替で解決)。

### デプロイ途中で失敗 → ロックが残った

```bash
DEPLOY_HOST=... php vendor/bin/dep deploy:unlock
```

## 運用メモ

- **サーバには Node.js を入れない**。ビルドはローカルで行い、`public/build/` のみ rsync する。Node バージョン齟齬で本番が動かないリスクを避けるため
- **マイグレーションの破壊的変更 (カラム削除等) は別 PR で先行デプロイ** する運用が安全。コード切替とスキーマ変更を分けることで、不測の失敗時に `rollback` で戻しやすい
- 本番 `.env` は `shared/` に置かれるため、リリース切替で消えない。変更したい時は `env:push` タスク経由で上書き
