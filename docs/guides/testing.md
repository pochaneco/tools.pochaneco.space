# テスト

```php
// tests/Feature/MyTest.php
test('admin can access', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->get('/admin')->assertOk();
});

// Inertiaテスト
use Inertia\Testing\AssertableInertia as Assert;

test('props are correct', function () {
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('user')
        );
});
```

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail artisan test --filter MyTest
```
