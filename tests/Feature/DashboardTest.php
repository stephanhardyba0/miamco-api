<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_expected_structure(): void
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'orders.view', 'module' => 'orders', 'action' => 'view', 'guard_name' => 'web']);
        $role->permissions()->attach($permission->id);

        $user = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'password' => bcrypt('password'),
            'pin' => bcrypt('0000'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'today' => ['orders', 'revenue'],
                'month' => ['orders', 'revenue'],
                'pending_orders',
                'completed_today_orders',
                'low_stock_items',
                'total_clients',
                'top_selling_items',
                'recent_orders',
                'unpaid_invoices',
                'cash_in_hand',
            ]);
    }
}
