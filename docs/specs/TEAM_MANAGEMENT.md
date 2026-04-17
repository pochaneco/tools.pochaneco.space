# チーム管理機能

## 概要

tools.pochaneco.spaceにチーム管理機能を追加しました。複数のユーザーが協力してプロジェクトやデータを管理できるようになります。

## 機能

### チーム
- ULID主キーを使用したチームの作成・編集・削除
- チーム名と説明
- オーナー（作成者）の自動設定
- ソフトデリート対応

### メンバー管理
- チームメンバーの追加・削除
- ロールベースのアクセス制御（Owner/Member）
- メンバーごとの権限設定

### ロールと権限

#### Owner（オーナー）
- チーム情報の編集・削除
- メンバーの招待・削除
- チーム設定の管理
- データの閲覧・編集
- すべての権限を保有

#### Member（メンバー）
- チームの閲覧
- データの閲覧・編集（設定可能）

## 技術仕様

### データベース

#### teamsテーブル
```sql
- id: ULID (主キー)
- name: VARCHAR(255)
- description: TEXT (nullable)
- owner_id: BIGINT UNSIGNED (users.id外部キー)
- created_at: TIMESTAMP
- updated_at: TIMESTAMP
- deleted_at: TIMESTAMP (nullable)
```

#### team_userテーブル（中間テーブル）
```sql
- id: BIGINT UNSIGNED (主キー)
- user_id: BIGINT UNSIGNED (users.id外部キー)
- team_id: ULID (teams.id外部キー)
- role: VARCHAR(255) (owner/member)
- created_at: TIMESTAMP
- updated_at: TIMESTAMP
- UNIQUE(user_id, team_id)
```

### モデル

#### Team (`app/Models/Team.php`)
- `HasUlids` トレイト使用
- `SoftDeletes` トレイト使用
- リレーション:
  - `owner()`: BelongsTo User
  - `members()`: BelongsToMany User (with pivot: role)

#### User (`app/Models/User.php`)
- リレーション追加:
  - `ownedTeams()`: HasMany Team
  - `teams()`: BelongsToMany Team (with pivot: role)

### Enum

#### TeamRole (`app/Enums/TeamRole.php`)
```php
enum TeamRole: string
{
    case OWNER = 'owner';
    case MEMBER = 'member';
    
    public function can(string $permission): bool
    public function isOwner(): bool
    public function isMember(): bool
}
```

権限チェック:
- Owner: すべての権限
- Member: view, viewData, editData

### ポリシー

#### TeamPolicy (`app/Policies/TeamPolicy.php`)

認可メソッド:
- `viewAny(User $user)`: すべてのチーム一覧を閲覧（認証済みユーザー全員）
- `view(User $user, Team $team)`: チーム詳細を閲覧（メンバーのみ）
- `create(User $user)`: チームを作成（認証済みユーザー全員）
- `update(User $user, Team $team)`: チームを更新（オーナーのみ）
- `delete(User $user, Team $team)`: チームを削除（オーナーのみ）
- `invite(User $user, Team $team)`: メンバーを招待（オーナーのみ）
- `removeMember(User $user, Team $team)`: メンバーを削除（オーナーのみ）
- `manageSettings(User $user, Team $team)`: 設定を管理（オーナーのみ）
- `viewData(User $user, Team $team)`: データを閲覧（メンバー全員、権限に応じて）
- `editData(User $user, Team $team)`: データを編集（権限を持つメンバー）

### コントローラー

#### TeamController (`app/Http/Controllers/TeamController.php`)
- `index()`: チーム一覧
- `create()`: チーム作成フォーム
- `store()`: チーム保存
- `show(Team $team)`: チーム詳細
- `edit(Team $team)`: チーム編集フォーム
- `update(Team $team)`: チーム更新
- `destroy(Team $team)`: チーム削除

#### TeamMemberController (`app/Http/Controllers/TeamMemberController.php`)
- `store(Team $team)`: メンバー追加
- `destroy(Team $team, User $user)`: メンバー削除

### ルート

```php
Route::middleware(['auth', 'verified'])->group(function () {
    // チームリソース
    Route::resource('teams', TeamController::class);
    
    // メンバー管理
    Route::post('teams/{team}/members', [TeamMemberController::class, 'store'])
        ->name('teams.members.store');
    Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])
        ->name('teams.members.destroy');
});
```

### フロントエンド

#### Vueコンポーネント
- `resources/js/pages/Teams/Index.vue`: チーム一覧
- `resources/js/pages/Teams/Create.vue`: チーム作成
- `resources/js/pages/Teams/Show.vue`: チーム詳細・メンバー管理
- `resources/js/pages/Teams/Edit.vue`: チーム編集

#### 国際化
- `resources/js/locales/ja.json`: 日本語翻訳
- `resources/js/locales/en.json`: 英語翻訳
- `lang/ja/teams.php`: Laravelメッセージ（日本語）
- `lang/en/teams.php`: Laravelメッセージ（英語）

## 使用方法

### チームの作成

1. サイドバーから「チーム」をクリック
2. 「チームを作成」ボタンをクリック
3. チーム名と説明を入力
4. 「作成」ボタンをクリック

作成したユーザーが自動的にオーナーとして設定されます。

### メンバーの追加

1. チーム詳細ページを開く
2. 「メンバーを招待」をクリック
3. ユーザーIDとロールを選択
4. 「追加」ボタンをクリック

**注意**: 現在の実装では既存ユーザーのIDによる追加のみサポートしています。メール招待機能は今後の実装予定です。

### メンバーの削除

1. チーム詳細ページを開く
2. メンバーリストで削除したいメンバーの「削除」ボタンをクリック

**注意**: オーナーは削除できません。

### チームの編集

1. チーム詳細ページを開く
2. 「チームを編集」リンクをクリック
3. チーム名や説明を変更
4. 「保存」ボタンをクリック

**権限**: オーナーのみ

### チームの削除

1. チーム詳細ページを開く
2. 削除ボタンをクリック（実装予定）

**権限**: オーナーのみ

## マイグレーション

```bash
# マイグレーション実行
./vendor/bin/sail artisan migrate

# ロールバック
./vendor/bin/sail artisan migrate:rollback
```

## テスト

```bash
# すべてのテスト
./vendor/bin/sail artisan test

# チーム関連のテストのみ
./vendor/bin/sail artisan test --filter Team

# Pestを使用
./vendor/bin/sail test --filter Team
```

**注意**: テストはまだ実装されていません。

## セキュリティ

- すべてのチーム操作は認証必須
- Policy による細かい権限制御
- CSRF 保護（Laravel標準）
- SQLインジェクション対策（Eloquent ORM）
- XSS対策（Vue自動エスケープ）

## 今後の実装予定

- [ ] メール招待機能（招待トークン）
- [ ] チーム設定画面
- [ ] メンバーの権限カスタマイズ
- [ ] チーム活動ログ
- [ ] チームアバター画像
- [ ] チーム検索・フィルター機能
- [ ] Feature テストとPolicyテストの作成
- [ ] Ziggy導入（route()ヘルパーの型安全化）

## トラブルシューティング

### チームが作成できない
- ログインしているか確認
- メール認証が完了しているか確認

### メンバーを追加できない
- チームのオーナーであることを確認
- 追加しようとしているユーザーIDが存在するか確認
- 既にメンバーになっていないか確認

### 権限エラーが発生する
- TeamPolicyが正しく登録されているか確認:
  ```bash
  # app/Providers/AuthServiceProvider.php を確認
  ```

## 関連ファイル

### バックエンド
- `database/migrations/*_create_teams_table.php`
- `database/migrations/*_create_team_user_table.php`
- `app/Models/Team.php`
- `app/Models/User.php`
- `app/Enums/TeamRole.php`
- `app/Policies/TeamPolicy.php`
- `app/Providers/AuthServiceProvider.php`
- `app/Http/Controllers/TeamController.php`
- `app/Http/Controllers/TeamMemberController.php`
- `routes/web.php`
- `lang/ja/teams.php`
- `lang/en/teams.php`

### フロントエンド
- `resources/js/pages/Teams/*.vue`
- `resources/js/locales/ja.json`
- `resources/js/locales/en.json`
- `resources/js/components/AppSidebar.vue`

## 参考資料

- [Laravel Policies](https://laravel.com/docs/12.x/authorization#creating-policies)
- [Inertia.js](https://inertiajs.com/)
- [Vue I18n](https://vue-i18n.intlify.dev/)
- [ULID Specification](https://github.com/ulid/spec)
