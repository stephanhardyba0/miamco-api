<?php

namespace Tests\Feature;

use App\Events\StockSaved;
use App\Models\AppNotification;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemIngredient;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\User;
use App\Services\SmartAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StockModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'Stock User',
            'username' => 'st-'.uniqid(),
            'email' => 'st-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Ouvre une session de caisse : un encaissement POS exige désormais
     * une caisse ouverte, faute de quoi la vente est refusée.
     */
    private function openCashSessionFor(User $user): void
    {
        $register = CashRegister::firstOrCreate(
            ['name' => 'Caisse Principale'],
            ['is_open' => false, 'current_balance' => 0]
        );

        CashRegisterSession::create([
            'cash_register_id' => $register->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opening_amount' => 500,
            'opened_at' => now(),
        ]);
    }

    private function makeDishWithIngredient(float $stockQty, float $perUnit, int $soldQty): array
    {
        $unique = uniqid();
        $category = MenuCategory::firstOrCreate(['slug' => 'cat-'.$unique], ['name' => 'Cat '.$unique]);
        $dish = MenuItem::create([
            'menu_category_id' => $category->id,
            'name' => 'Plat '.$unique,
            'slug' => 'plat-'.$unique,
            'price' => 2000,
            'is_available' => true,
        ]);
        $stock = StockItem::create([
            'name' => 'Ingrédient '.$unique,
            'unit' => 'kg',
            'quantity' => $stockQty,
            'min_quantity' => 1,
            'unit_cost' => 500,
        ]);
        MenuItemIngredient::create([
            'menu_item_id' => $dish->id,
            'stock_item_id' => $stock->id,
            'quantity' => $perUnit,
        ]);

        return [$dish, $stock];
    }

    public function test_pos_sale_deducts_stock_and_records_movement(): void
    {
        $admin = $this->makeUser();
        $this->openCashSessionFor($admin);
        [$dish, $stock] = $this->makeDishWithIngredient(10, 0.5, 2);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/pos/sale', [
            'payment_method' => 'cash',
            'items' => [['menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => 2000]],
        ]);

        $response->assertCreated();

        // 10 − (0.5 × 2 vendus) = 9
        $this->assertDatabaseHas('stock_items', ['id' => $stock->id, 'quantity' => 9]);
        $this->assertDatabaseHas('stock_movements', [
            'stock_item_id' => $stock->id,
            'type' => 'out',
            'quantity' => 1,
        ]);
    }

    public function test_serving_an_order_deducts_stock(): void
    {
        $admin = $this->makeUser();
        [$dish, $stock] = $this->makeDishWithIngredient(5, 1, 1);

        $order = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/sync/push', [
            'orders' => [
                [
                    'local_id' => 'srv-1',
                    'table_id' => null,
                    'items' => [['menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => 2000]],
                ],
            ],
        ])->json('results.0.server_id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/orders/{$order}/serve")
            ->assertOk();

        $this->assertDatabaseHas('stock_items', ['id' => $stock->id, 'quantity' => 4]);
    }

    public function test_manual_out_movement_cannot_exceed_stock(): void
    {
        $admin = $this->makeUser();
        $stock = StockItem::create([
            'name' => 'Sel', 'unit' => 'kg', 'quantity' => 2, 'min_quantity' => 1, 'unit_cost' => 100,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-movements', [
            'stock_item_id' => $stock->id,
            'type' => 'out',
            'quantity' => 5,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('stock_items', ['id' => $stock->id, 'quantity' => 2]);
    }

    public function test_quantity_cannot_be_edited_directly(): void
    {
        $admin = $this->makeUser();
        $stock = StockItem::create([
            'name' => 'Riz', 'unit' => 'kg', 'quantity' => 7, 'min_quantity' => 2, 'unit_cost' => 300,
        ]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/stock-items/{$stock->id}", [
            'name' => 'Riz', 'quantity' => 999,
        ])->assertOk();

        $this->assertDatabaseHas('stock_items', ['id' => $stock->id, 'quantity' => 7]);
    }

    public function test_index_returns_server_stats(): void
    {
        $admin = $this->makeUser();
        StockItem::create(['name' => 'A', 'unit' => 'kg', 'quantity' => 0, 'min_quantity' => 1, 'unit_cost' => 10]);
        StockItem::create(['name' => 'B', 'unit' => 'kg', 'quantity' => 1, 'min_quantity' => 2, 'unit_cost' => 20]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/stock-items');

        $response->assertOk()
            ->assertJsonPath('stats.total', 2)
            ->assertJsonPath('stats.low_stock', 2)
            ->assertJsonPath('stats.out_of_stock', 1)
            ->assertJsonPath('stats.total_value', 20);
    }

    public function test_movement_dispatches_stock_saved(): void
    {
        Event::fake([StockSaved::class]);
        $admin = $this->makeUser();
        $stock = StockItem::create([
            'name' => 'Eau', 'unit' => 'L', 'quantity' => 10, 'min_quantity' => 1, 'unit_cost' => 1,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-movements', [
            'stock_item_id' => $stock->id,
            'type' => 'in',
            'quantity' => 5,
        ])->assertCreated();

        Event::assertDispatched(StockSaved::class);
    }

    public function test_low_stock_alert_is_deduplicated_within_24h(): void
    {
        $this->makeUser();
        StockItem::create(['name' => 'Farine', 'unit' => 'kg', 'quantity' => 0, 'min_quantity' => 5, 'unit_cost' => 1]);

        SmartAlertService::checkLowStock();
        SmartAlertService::checkLowStock();

        $this->assertSame(1, AppNotification::where('type', 'low_stock')->count());
    }
}
