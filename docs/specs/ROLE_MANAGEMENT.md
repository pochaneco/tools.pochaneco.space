# Role Management System

## 概要

`is_admin` のboolean型から、より柔軟な **role-based access control (RBAC)** システムに変更しました。

データベースレベルでENUM型を使用し、デフォルトは`guest`ロールです。

## ロールの種類

| Role | Value | デフォルト | 説明 |
|------|-------|-----------|------|
| Admin | `admin` | | 管理者 - 全権限 |
| Moderator | `moderator` | | モデレーター - 一部管理機能 |
| User | `user` | | 通常ユーザー - 基本機能のみ |
| Guest | `guest` | ✅ | ゲスト - 制限付きアクセス |

### ロールの使い分け

- **Guest**: 新規登録直後、メール未認証、または制限付きアクセス用
- **User**: 通常ユーザー
- **Moderator**: コンテンツモデレーション権限を持つユーザー
- **Admin**: システム管理者

## Enum定義

`App\Enums\UserRole` を使用:

```php
use App\Enums\UserRole;

// Enum cases
UserRole::ADMIN
UserRole::MODERATOR
UserRole::USER
UserRole::GUEST
```

## Userモデルのメソッド

### ロールの確認

```php
$user = Auth::user();

// Admin権限チェック
if ($user->isAdmin()) {
    // Admin専用処理
}

// Moderator以上の権限チェック
if ($user->isModerator()) {
    // ModeratorまたはAdmin
}

// 特定のロールチェック
if ($user->hasRole(UserRole::ADMIN)) {
    // Admin専用
}

// 複数ロールのいずれかをチェック
if ($user->hasAnyRole([UserRole::ADMIN, UserRole::MODERATOR])) {
    // AdminまたはModerator
}
```

### ロールの割り当て

```php
$user = User::find(1);

// ロールを変更
$user->assignRole(UserRole::MODERATOR);

// または直接代入
$user->role = UserRole::ADMIN;
$user->save();
```

## ミドルウェアの使用

### ルート保護

`routes/web.php` での使用例:

```php
use App\Enums\UserRole;

// Admin専用ルート
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin/dashboard', [AdminController::class, 'index']);
    Route::get('/admin/users', [AdminController::class, 'users']);
});

// ModeratorまたはAdmin
Route::middleware(['auth', 'role:admin,moderator'])->group(function () {
    Route::get('/moderation', [ModerationController::class, 'index']);
});

// 通常ユーザー以上
Route::middleware(['auth', 'role:user,moderator,admin'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});
```

### コントローラーでの使用

```php
use App\Enums\UserRole;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:admin');
    }

    public function users()
    {
        // Admin専用処理
        $users = User::all();
        return view('admin.users', compact('users'));
    }
}
```

## Bladeテンプレートでの使用

```php
@if(Auth::user()->isAdmin())
    <a href="/admin">管理画面</a>
@endif

@if(Auth::user()->isModerator())
    <a href="/moderation">モデレーション</a>
@endif

{{-- または --}}
@if(Auth::user()->hasRole(App\Enums\UserRole::ADMIN))
    <button>削除</button>
@endif
```

## Vue/Inertiaでの使用

### Inertia共有データ

`app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => [
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'role' => $request->user()->role->value,
                'isAdmin' => $request->user()->isAdmin(),
                'isModerator' => $request->user()->isModerator(),
            ] : null,
        ],
    ];
}
```

### Vueコンポーネント

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()
const auth = computed(() => page.props.auth)

const isAdmin = computed(() => auth.value.user?.isAdmin)
const isModerator = computed(() => auth.value.user?.isModerator)
</script>

<template>
  <div v-if="isAdmin">
    <h2>Admin Panel</h2>
    <!-- Admin content -->
  </div>
  
  <div v-if="isModerator">
    <h2>Moderation Panel</h2>
    <!-- Moderator content -->
  </div>
</template>
```

## Policyの使用 (推奨)

より細かい権限制御にはLaravelのPolicyを使用します:

### Policy作成

```bash
php artisan make:policy PostPolicy --model=Post
```

`app/Policies/PostPolicy.php`:

```php
namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::ADMIN, 
            UserRole::MODERATOR, 
            UserRole::USER
        ]);
    }

    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id 
            || $user->isModerator();
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id 
            || $user->isAdmin();
    }
}
```

### Policyの使用

コントローラー:

```php
public function update(Request $request, Post $post)
{
    $this->authorize('update', $post);
    
    // Update logic
}
```

Blade:

```php
@can('update', $post)
    <a href="{{ route('posts.edit', $post) }}">編集</a>
@endcan

@can('delete', $post)
    <form action="{{ route('posts.destroy', $post) }}" method="POST">
        @csrf
        @method('DELETE')
        <button type="submit">削除</button>
    </form>
@endcan
```

## データベースクエリ

```php
// 全Admin取得
$admins = User::where('role', UserRole::ADMIN)->get();

// AdminとModeratorを取得
$staff = User::whereIn('role', [
    UserRole::ADMIN, 
    UserRole::MODERATOR
])->get();

// User以上のロールを持つユーザー
$activeUsers = User::whereIn('role', [
    UserRole::USER,
    UserRole::MODERATOR,
    UserRole::ADMIN
])->get();
```

## Seeder

初期管理者の作成:

```php
use App\Enums\UserRole;

User::create([
    'name' => 'Admin',
    'email' => env('ORENO_EMAIL', 'admin@example.com'),
    'password' => bcrypt('password'),
    'email_verified_at' => now(),
    'role' => UserRole::ADMIN,
]);
```

## マイグレーション

データベースカラムはENUM型で定義:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'moderator', 'user', 'guest'])
                ->default('guest')
                ->after('email');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
```

### デフォルト値の利点

デフォルトを`guest`にすることで:
- ✅ 新規ユーザーは最小権限でスタート (Principle of Least Privilege)
- ✅ メール認証前のユーザーを安全に扱える
- ✅ 明示的にロールをアップグレードする必要がある
- ✅ セキュリティリスクの低減

既存の `is_admin` カラムからの移行:

```php
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add role column
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('user')->after('email');
            $table->index('role');
        });

        // Migrate existing is_admin to role (if is_admin exists)
        if (Schema::hasColumn('users', 'is_admin')) {
            DB::table('users')
                ->where('is_admin', true)
                ->update(['role' => UserRole::ADMIN->value]);
            
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        DB::table('users')
            ->where('role', UserRole::ADMIN->value)
            ->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
```

## テスト

```php
use App\Enums\UserRole;
use App\Models\User;

test('admin can access admin dashboard', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    
    $this->actingAs($admin)
        ->get('/admin/dashboard')
        ->assertOk();
});

test('user cannot access admin dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::USER]);
    
    $this->actingAs($user)
        ->get('/admin/dashboard')
        ->assertForbidden();
});
```

## ベストプラクティス

1. **Enumを使用**: 常に `UserRole` enumを使用し、文字列の直接使用は避ける
2. **Policyを活用**: 複雑な権限ロジックはPolicyに移動
3. **ミドルウェアで保護**: ルートレベルで基本的な保護を実装
4. **明示的なチェック**: コントローラーでは `$this->authorize()` を使用
5. **フロントエンドでも確認**: UIの表示/非表示をロールに応じて制御

## まとめ

この実装により:
- ✅ 柔軟なロール管理
- ✅ 簡単に拡張可能
- ✅ 型安全 (Enum使用)
- ✅ 保守しやすいコード
- ✅ Laravel標準に準拠
