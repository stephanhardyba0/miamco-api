<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'Status Admin',
            'username' => 'st-'.uniqid(),
            'email' => 'st-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_root_serves_status_page_not_documentation(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringContainsString('tat des services', $response->getContent());
        $this->assertStringContainsString('1.9a', $response->getContent());
        $this->assertStringNotContainsString('Scribe', $response->getContent());
    }

    public function test_root_json_format_for_tools(): void
    {
        $response = $this->getJson('/?format=json');
        // 200 = tout vert, 503 = degrade (le websocket Reverb tourne sur le serveur, pas pendant les tests)
        $this->assertContains($response->status(), [200, 503]);
        $response->assertJsonPath('version', '1.9a')
            ->assertJsonPath('service', 'Miam & Co API');
    }

    public function test_documentation_moved_to_docs(): void
    {
        $this->get('/docs')->assertOk();
    }

    public function test_orders_search_by_client_name_works(): void
    {
        $admin = $this->makeAdmin();
        $client = Client::create([
            'first_name' => 'Recherche',
            'last_name' => 'Test',
            'email' => uniqid().'@cg',
        ]);
        Order::create([
            'order_number' => 'ORD-SEARCH-1',
            'status' => 'served',
            'total' => 100,
            'client_id' => $client->id,
            'user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/orders?search=Recherche');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotEmpty($ids);
    }

    public function test_invoices_stats_are_global_not_per_page(): void
    {
        $admin = $this->makeAdmin();
        $payload = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/invoices?per_page=1')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('stats', $payload);
        $this->assertArrayHasKey('total', $payload['stats']);
        $this->assertSame(Invoice::count(), $payload['stats']['total']);
    }
}
