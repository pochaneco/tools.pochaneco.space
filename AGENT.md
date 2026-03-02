# AI Agent開発ガイド - tools.pochaneco.space

このドキュメントは、AI Agentがこのプロジェクトで開発を行う際の重要な情報をまとめたものです。

## プロジェクト概要

**tools.pochaneco.space** は、個人開発者「ぽちゃねこ」が開発・公開している各種ツールをまとめたWebアプリケーションです。

**技術スタック**: Laravel 12 + Inertia.js 2 + Vue 3

## 🚨 重要な制約事項

### 1. Inertia.jsアーキテクチャ

```
❌ 間違い: Bladeテンプレート、APIエンドポイント
✅ 正しい: Inertia::render()でPropsを渡す
```

詳細: [docs/architecture/inertia.md](./docs/architecture/inertia.md)

### 2. RBAC（Role-Based Access Control）

ロール階層: `admin` > `moderator` > `vip` > `user` > `monitor` > `guest`

詳細: [docs/ROLE_MANAGEMENT.md](./docs/ROLE_MANAGEMENT.md)

### 3. データベース操作

```bash
# ❌ 間違い: 手動でSQL実行
# ✅ 正しい: Artisanコマンドを使用

./vendor/bin/sail artisan make:migration create_table_name
./vendor/bin/sail artisan migrate
```

## ドキュメント

すべてのドキュメントは `docs/` ディレクトリに整理されています。

### クイックリンク

- **[ドキュメント目次](./docs/README.md)** - 全体構成
- **[環境構築](./docs/development/setup.md)** - 初期セットアップ
- **[実装パターン](./docs/guides/patterns.md)** - よくあるパターン集
- **[トラブルシューティング](./docs/reference/troubleshooting.md)** - エラー対処

### アーキテクチャ

- [技術スタック](./docs/architecture/stack.md)
- [Inertia.jsガイド](./docs/architecture/inertia.md)
- [ディレクトリ構造](./docs/architecture/directory.md)

### 開発ガイド

- [環境構築](./docs/development/setup.md)
- [開発ワークフロー](./docs/development/workflow.md)

### 実装ガイド

- [実装パターン](./docs/guides/patterns.md)
- [テスト](./docs/guides/testing.md)
- [国際化](./docs/guides/i18n.md)
- [デバッグ](./docs/guides/debugging.md)

### 機能別

- [ロール管理システム](./docs/ROLE_MANAGEMENT.md)
- [チーム管理機能](./docs/TEAM_MANAGEMENT.md)
- [お問い合わせシステム](./docs/CONTACT_SYSTEM_README.md)

## 開発チェックリスト

新しい機能を実装する際は:

- [ ] `AGENT.md`（このファイル）と関連ドキュメントを確認
- [ ] 既存のコード構造に従う
- [ ] Inertia.jsのパターンを使用（従来のAPI不可）
- [ ] ロールベースのアクセス制御を実装
- [ ] マイグレーションはArtisanコマンドを使用
- [ ] ドキュメント（`docs/`）を更新
- [ ] テストを書く（Pest推奨）

## コマンド実行の注意

PHPコマンドやNPMコマンドは **Sailコマンド** を使用してDockerコンテナ内で実行してください。

```bash
# 例
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm run dev
./vendor/bin/sail test
```
