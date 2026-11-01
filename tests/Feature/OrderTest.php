<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Table $table;

    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'orders.view', 'module' => 'orders', 'action' => 'view', 'guard_name' => 'web']);
        $permission2 = Permission::create(['name' => 'orders.create', 'module' => 'orders', 'action' => 'create', 'guard_name' => 'web']);
        $role->permissions()->attach([$permission->id, $permission2->id]);

        $this->user = User::create([
            'name' => 'Test Admin',
            'username' => 'admin',
            'password' => bcrypt('password'),
            'pin' => bcrypt('0000'),
            'is_active' => true,
        ]);
        $this->user->roles()->attach($role->id);

        $zone = Zone::create(['name' => 'Terrasse']);
        $this->table = Table::create(['name' => 'T1', 'zone_id' => $zone->id, 'seats' => 4]);
        $category = MenuCategory::create(['name' => 'Boissons', 'slug' => 'boissons']);
        $this->menuItem = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Café',
            'price' => 1500,
            'is_available' => true,
        ]);
    }

    public function test_can_list_orders(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/orders');

        $response->assertOk();
    }

    public function test_can_create_order(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/orders', [
                'table_id' => $this->table->id,
                'items' => [
                    [
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 2,
                        'unit_price' => 1500,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Commande créée.');

        $this->assertDatabaseHas('orders', ['table_id' => $this->table->id]);
        $this->assertDatabaseHas('order_items', ['menu_item_id' => $this->menuItem->id, 'quantity' => 2]);
    }

    public function test_cannot_create_order_without_items(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/orders', [
                'table_id' => $this->table->id,
                'items' => [],
            ]);

        $response->assertStatus(422);
    }

    public function test_can_show_order(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-000001',
            'table_id' => $this->table->id,
            'user_id' => $this->user->id,
            'status' => 'pending',
            'total' => 3000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('order.order_number', 'ORD-000001');
    }
}
