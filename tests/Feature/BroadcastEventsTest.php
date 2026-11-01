<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Events\OrderSaved;
use App\Events\TableSaved;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'Broadcast User',
            'username' => 'bc-'.uniqid(),
            'email' => 'bc-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_creating_an_order_broadcasts_order_saved(): void
    {
        Event::fake([OrderSaved::class]);

        $admin = $this->makeUser();
        $unique = uniqid();
        $category = MenuCategory::firstOrCreate(['slug' => 'cat-'.$unique], ['name' => 'Cat '.$unique]);
        $item = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Plat '.$unique,
            'slug' => 'plat-'.$unique,
            'price' => 500,
            'is_available' => true,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/sync/push', [
            'orders' => [
                [
                    'local_id' => 'bc-1',
                    'table_id' => null,
                    'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 500]],
                ],
            ],
        ])->assertOk();

        Event::assertDispatched(OrderSaved::class, fn ($e) => $e->action === 'created');
    }

    public function test_changing_order_status_broadcasts_order_saved(): void
    {
        Event::fake([OrderSaved::class]);

        $admin = $this->makeUser();
        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'user_id' => $admin->id,
            'status' => 'pending',
            'total' => 100,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/orders/{$order->id}/status", ['status' => 'preparing'])
            ->assertOk();

        Event::assertDispatched(OrderSaved::class, fn ($e) => $e->action === 'updated');
    }

    public function test_occupying_a_table_broadcasts_table_saved(): void
    {
        Event::fake([TableSaved::class]);

        $admin = $this->makeUser();
        $table = Table::create([
            'name' => 'BC '.uniqid(),
            'seats' => 4,
            'status' => 'available',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tables/{$table->id}/occupy")
            ->assertOk();

        Event::assertDispatched(TableSaved::class);
    }

    public function test_notifying_a_user_broadcasts_notification_created(): void
    {
        Event::fake([NotificationCreated::class]);

        $admin = $this->makeUser();
        NotificationService::notifyUser($admin->id, 'Test', 'Message', 'system');

        Event::assertDispatched(NotificationCreated::class);
    }
}
