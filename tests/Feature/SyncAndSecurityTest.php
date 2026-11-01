<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::create([
            'name' => $roleName.' User',
            'username' => strtolower($roleName).'-'.uniqid(),
            'email' => strtolower($roleName).'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeMenuItem(float $price): MenuItem
    {
        $unique = uniqid();
        $category = MenuCategory::firstOrCreate(
            ['slug' => 'test-cat-'.$unique],
            ['name' => 'Test Cat '.$unique]
        );

        return MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Plat '.$unique,
            'slug' => 'plat-'.$unique,
            'price' => $price,
            'is_available' => true,
        ]);
    }

    public function test_sync_push_accepts_orders_without_table(): void
    {
        $admin = $this->makeUser('Admin');
        $item = $this->makeMenuItem(500);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/sync/push', [
            'orders' => [
                [
                    'local_id' => 'local-test-1',
                    'table_id' => null,
                    'items' => [
                        ['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 500],
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'synced');
    }

    public function test_order_total_uses_catalog_price_not_client_price(): void
    {
        $admin = $this->makeUser('Admin');
        $item = $this->makeMenuItem(500);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/sync/push', [
            'orders' => [
                [
                    'local_id' => 'local-test-2',
                    'table_id' => null,
                    'items' => [
                        ['menu_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 1],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $orderId = $response->json('results.0.server_id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'total' => 1000,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'unit_price' => 500,
            'total_price' => 1000,
        ]);
    }

    public function test_deactivating_user_revokes_tokens(): void
    {
        $admin = $this->makeUser('Admin');
        $target = $this->makeUser('Caissier');
        $target->createToken('device-1');
        $target->createToken('device-2');

        $this->assertCount(2, $target->tokens()->get());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/users/{$target->id}/toggle-active")
            ->assertOk();

        $this->assertCount(0, $target->tokens()->get());
    }

    public function test_changing_password_revokes_other_tokens(): void
    {
        $user = $this->makeUser('Caissier');
        $keep = $user->createToken('current-device')->plainTextToken;
        $user->createToken('other-device');

        $response = $this->withHeader('Authorization', "Bearer $keep")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]);

        $response->assertOk();
        $this->assertCount(1, $user->tokens()->get());
        $this->assertSame(
            (int) explode('|', $keep)[0],
            (int) $user->tokens()->first()->id
        );
    }
}
