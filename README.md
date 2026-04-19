# tools.pochaneco.space

個人開発者「ぽちゃねこ」が開発・公開している各種ツールをまとめたWebアプリケーションです。

## 技術スタック

- **フロントエンド**: Vue 3 + Inertia.js 2 + TypeScript + Tailwind CSS 4
- **バックエンド**: Laravel 12 + PHP 8.3 + MySQL
- **インフラ**: Laravel Sail (Docker)
- **認証**: Laravel Fortify（メール認証、2FA対応）
- **テスト**: Pest 4
- **国際化**: 日本語・英語対応

## 主要機能

### 認証・権限管理
- **RBAC（Role-Based Access Control）**: 柔軟なロールベース権限管理
  - ロール階層: `admin` > `moderator` > `vip` > `user` > `monitor` > `guest`
  - 詳細: [docs/ROLE_MANAGEMENT.md](./docs/ROLE_MANAGEMENT.md)

### チーム管理
- チームの作成・編集・削除
- メール招待によるメンバー管理
- ロールベースのアクセス制御（Owner/Member）
- 詳細: [docs/TEAM_MANAGEMENT.md](./docs/TEAM_MANAGEMENT.md)

### AIチャット機能
- ストリーミング対応のAIチャット
- 会話履歴の保存・管理

## クイックスタート

```bash
# 環境変数のセットアップ
cp .env.example .env
# ORENO_EMAILを設定してください

# Docker環境の起動とパッケージインストール
./vendor/bin/sail up -d
./vendor/bin/sail composer install
./vendor/bin/sail npm install
./vendor/bin/sail artisan key:generate

# データベースのセットアップ
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed

# 開発サーバーの起動
./vendor/bin/sail npm run dev
```

ブラウザで http://localhost にアクセス

## 開発ガイド

詳細なドキュメントは `docs/` ディレクトリにあります。

- **[ドキュメント目次](./docs/README.md)** - 全体構成
- **[AI Agent開発ガイド](./AGENT.md)** - AI開発時の重要事項
- **[環境構築](./docs/development/setup.md)** - 初期セットアップ詳細
- **[開発ワークフロー](./docs/development/workflow.md)** - 日常的な開発作業
- **[デプロイ](./docs/development/deployment.md)** - Deployer 経由の本番デプロイ手順
- **[実装パターン](./docs/guides/patterns.md)** - よくあるパターン集
- **[テスト](./docs/guides/testing.md)** - Pestによるテスト作成
- **[トラブルシューティング](./docs/reference/troubleshooting.md)** - エラー対処法

## 重要な制約事項

### Inertia.jsアーキテクチャ
このプロジェクトは **Inertia.js** を使用しています。

```php
// ❌ 間違い: Bladeビュー、JSON APIエンドポイント
return view('users.index', ['users' => $users]);
return response()->json(['users' => $users]);

// ✅ 正しい: Inertia::render()でPropsを渡す
return Inertia::render('Users/Index', [
    'users' => $users
]);
```

詳細: [docs/architecture/inertia.md](./docs/architecture/inertia.md)

### Sailコマンドの使用
すべてのPHP/NPMコマンドは `./vendor/bin/sail` 経由で実行してください。

```bash
# 例
./vendor/bin/sail artisan migrate
./vendor/bin/sail composer require package-name
./vendor/bin/sail npm run build
./vendor/bin/sail test
```

## ライセンス

このプロジェクトは [MIT License](./LICENSE) の下で公開されています。
