# AI Agent 開発ガイド

## 言語設定
- **応答言語**: 日本語

## プロジェクト概要

**tools.pochaneco.space** - Laravel 12 + Inertia.js 2 + Vue 3 のWebアプリケーション

## 重要な制約

1. **Inertia.js必須** - Bladeテンプレート・JSON API禁止、`Inertia::render()` を使用
2. **RBAC** - ロール階層: `admin` > `moderator` > `vip` > `user` > `monitor` > `guest`
3. **Sailコマンド** - PHP/NPMコマンドは `./vendor/bin/sail` 経由で実行

## よく使うコマンド

```bash
./vendor/bin/sail up -d                  # コンテナ起動
./vendor/bin/sail composer run dev       # 全サービス一括起動（server, queue, logs, vite）
./vendor/bin/sail artisan migrate        # マイグレーション実行
./vendor/bin/sail artisan test           # テスト実行
./vendor/bin/sail vendor/bin/pint --dirty # PHP フォーマット（変更ファイルのみ）
```

全コマンドは [docs/development/workflow.md](./docs/development/workflow.md) を参照

## ドキュメント

- [技術スタック](./docs/architecture/stack.md)
- [Inertia.js](./docs/architecture/inertia.md) - 重要な制約
- [ディレクトリ構造](./docs/architecture/directory.md)
- [環境構築](./docs/development/setup.md)
- [ワークフロー](./docs/development/workflow.md)
- [実装パターン](./docs/guides/patterns.md)
- [テスト](./docs/guides/testing.md)
- [国際化](./docs/guides/i18n.md)
- [デバッグ](./docs/guides/debugging.md)
- [ロール管理](./docs/ROLE_MANAGEMENT.md)
- [チーム管理](./docs/TEAM_MANAGEMENT.md)
- [トラブルシューティング](./docs/reference/troubleshooting.md)
- [外部リソース](./docs/reference/resources.md)
