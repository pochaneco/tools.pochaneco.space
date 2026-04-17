# テストガイドライン

## 基本方針

- **Pest PHP v4** を使用（PHPUnit 直書き禁止）
- Feature テストを中心に、Unit テストは純粋ロジックのみ
- すべての変更にテストを書く。手動確認だけで済ませない
- happy path / failure path / edge case をカバーする

## ディレクトリ構成

```
tests/
├── Feature/
│   ├── Auth/                # 認証・登録・パスワードリセット
│   ├── Settings/            # プロフィール・パスワード・2FA
│   ├── TeamTest.php         # チーム CRUD
│   ├── TeamInvitationTest.php
│   ├── TeamMemberManagementTest.php
│   ├── ChatTest.php
│   └── DashboardTest.php
├── Unit/
│   └── TeamRoleTest.php     # Enum / 値オブジェクト等
└── Pest.php                 # グローバル設定
```

> モジュラモノリス移行後は `tests/Feature/{Module}/` に整理する。

## テスト作成コマンド

```bash
# Feature テスト（デフォルト）
./vendor/bin/sail artisan make:test TeamTest --pest --no-interaction

# Unit テスト
./vendor/bin/sail artisan make:test TeamRoleTest --pest --unit --no-interaction
```

## テスト実行

```bash
# 全テスト
./vendor/bin/sail artisan test

# ファイル指定
./vendor/bin/sail artisan test tests/Feature/TeamTest.php

# テスト名フィルタ
./vendor/bin/sail artisan test --filter="can create a team"
```

変更に関連する最小限のテストを実行してから、全体テストを確認する。

## 書き方の規約

### テスト関数

`it()` と `test()` どちらも可。プロジェクト内では混在しているが、新規ファイルでは `it()` を推奨する。

```php
// 推奨
it('can create a team', function () { ... });

// 既存コードで使われている形もOK
test('guests are redirected to the login page', function () { ... });
```

### グルーピング

関連テストは `describe()` でまとめ、共通セットアップは `beforeEach()` に書く。

```php
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->ownedBy($this->owner)->create();
    $this->team->members()->attach($this->owner->id, ['role' => TeamRole::OWNER->value]);
});

describe('Team creation', function () {
    it('can create a team', function () { ... });
    it('requires a name to create a team', function () { ... });
});

describe('Team viewing', function () {
    it('allows owner to view team', function () { ... });
    it('prevents non-member from viewing team', function () { ... });
});
```

### アサーション

ステータスコードは専用メソッドを使う。`assertStatus(403)` は使わない。

```php
// 良い
$response->assertSuccessful();
$response->assertForbidden();
$response->assertNotFound();
$response->assertRedirect('/login');

// 悪い
$response->assertStatus(200);
$response->assertStatus(403);
```

### Inertia テスト

`assertInertia()` でコンポーネント名と props を検証する。

```php
use Inertia\Testing\AssertableInertia as Assert;

it('renders chat page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('chat.index'))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->component('Chat/Index')
            ->has('messages')
        );
});
```

### 認証テスト

認証が必要なエンドポイントには、未認証のリダイレクトテストも書く。

```php
it('requires authentication', function () {
    $this->get('/teams')->assertRedirect('/login');
});

it('allows authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/teams')
        ->assertSuccessful();
});
```

### 認可テスト

RBAC のロールごとのアクセス制御をテストする。

```php
it('allows owner to update team', function () {
    $this->actingAs($this->owner)
        ->put("/teams/{$this->team->id}", ['name' => 'New Name'])
        ->assertRedirect();
});

it('prevents member from updating team', function () {
    $this->actingAs($this->member)
        ->put("/teams/{$this->team->id}", ['name' => 'New Name'])
        ->assertForbidden();
});
```

### バリデーションテスト

FormRequest のルールごとにテストする。dataset の活用も検討する。

```php
it('validates email format for invitation', function () {
    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/members", [
            'email' => 'invalid-email',
            'role' => 'member',
        ])
        ->assertSessionHasErrors('email');
});

// dataset で複数パターンをまとめる
it('rejects invalid emails', function (string $email) {
    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/members", [
            'email' => $email,
            'role' => 'member',
        ])
        ->assertSessionHasErrors('email');
})->with(['', 'not-an-email', '@example.com']);
```

## モック

外部サービス（メール・通知・イベント）はフェイクを使う。

```php
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Event;

it('sends invitation email', function () {
    Mail::fake();

    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/members", [
            'email' => 'new@example.com',
            'role' => 'member',
        ]);

    Mail::assertQueued(TeamInvitationMail::class, function ($mail) {
        return $mail->hasTo('new@example.com');
    });
});
```

## ファクトリの活用

- モデル作成には必ずファクトリを使う
- ファクトリにカスタム state があれば活用する（手動セットアップを減らす）

```php
// ファクトリの state を活用
$invitation = TeamInvitation::factory()
    ->forTeam($this->team)
    ->invitedBy($this->owner)
    ->forEmail('test@example.com')
    ->expired()     // カスタム state
    ->create();

// User::factory() のデフォルトを使う
$user = User::factory()->create();
$unverified = User::factory()->unverified()->create();
```

## テストカバレッジの指針

新機能を追加する際の最低限のテスト項目：

| 対象 | テスト内容 |
|------|-----------|
| Controller | 認証・認可・正常系・異常系・リダイレクト |
| FormRequest | 各バリデーションルール |
| Model | リレーション・スコープ・カスタムメソッド |
| Enum | 値・ラベル・権限判定 |
| Policy | 各アクションの許可・拒否 |
| Mail/Notification | 送信先・内容・条件分岐 |
