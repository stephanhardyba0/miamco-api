<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'Admin User',
            'username' => 'admin-'.uniqid(),
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_mutating_request_writes_audit_log(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/zones', ['name' => 'Zone Audit', 'color' => 'rose']);

        $response->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'created',
            'model_type' => Zone::class,
        ]);
    }

    public function test_audit_log_does_not_store_passwords(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/zones', ['name' => 'Zone Secret', 'password' => 'super-secret'])
            ->assertCreated();

        $this->assertDatabaseMissing('audit_logs', [
            'new_values' => '{"name":"Zone Secret","password":"super-secret"}',
        ]);
    }
}
