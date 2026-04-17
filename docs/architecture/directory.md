# ディレクトリ構造

モジュラモノリス構成。ドメイン（Auth / Team / Chat 等）単位でバックエンド・フロントエンドをまとめる。

## モジュール一覧

| モジュール | バックエンド | フロントエンド | 概要 |
|-----------|------------|--------------|------|
| Auth | `app/Modules/Auth/` | `resources/js/modules/auth/` | 認証・登録・メール確認 |
| Chat | `app/Modules/Chat/` | `resources/js/modules/chat/` | チャット機能 |
| Dashboard | `app/Modules/Dashboard/` | `resources/js/modules/dashboard/` | ダッシュボード |
| Settings | `app/Modules/Settings/` | `resources/js/modules/settings/` | プロフィール・パスワード・2FA |
| Team | `app/Modules/Team/` | `resources/js/modules/team/` | チーム・招待・メンバー管理 |

## バックエンド構造

```
app/
├── Modules/{Module}/            # ドメインモジュール
│   ├── Controllers/
│   ├── Models/                  # モジュール固有モデル
│   ├── Requests/                # モジュール固有 FormRequest
│   ├── Responses/               # Inertia Response クラス
│   ├── Enums/ Policies/ Mail/   # 必要に応じて追加
│   └── routes.php               # モジュールルート定義
│
├── Enums/                       # 共有 Enum（UserRole 等）
├── Http/
│   ├── Controllers/Controller.php
│   └── Middleware/              # 共有ミドルウェア
├── Models/                      # 共有モデル（User 等）
└── Providers/
```

### モジュール規約

- 最小構成: **Controllers/ + routes.php**。Models / Requests 等は必要に応じて追加
- 複数モジュールが共有するクラスは `app/` 直下に残す
- 名前空間: `App\Modules\{Module}\{Layer}`
- `routes.php` は `bootstrap/app.php` で読み込む

## フロントエンド構造

```
resources/js/
├── modules/{module}/            # ドメインモジュール
│   ├── pages/                   # Inertia ページ
│   └── components/              # モジュール固有コンポーネント
│
├── components/                  # 共有コンポーネント（ui/ 含む）
├── composables/                 # 共有 composable
├── layouts/                     # 共有レイアウト
├── lib/                         # 共有ユーティリティ
├── locales/                     # i18n（ja.json, en.json）
└── types/                       # 共有型定義
```

### フロントエンド規約

- Inertia ページ: `resources/js/modules/{module}/pages/` に配置
- 共有コンポーネント: `resources/js/components/`
- `Inertia::render()` のパスは `modules/{module}/pages/` を参照するよう Vite を設定

## その他

| ディレクトリ | 説明 |
|------------|------|
| `routes/web.php` | 共有ルート + モジュール routes の読み込み |
| `tests/Feature/{Module}/` | モジュール単位でテストを整理 |
| `database/migrations/` | 時系列管理（モジュール別に分割しない） |
| `database/factories/` | モデルファクトリ |
