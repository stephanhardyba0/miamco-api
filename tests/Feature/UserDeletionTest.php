<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression : la suppression d'un utilisateur passait par
 * `roles()->pluck('id')` — select non qualifie sur la jointure
 * roles × user_role, ambigu en MySQL (erreur 1052 → 500).
 */
class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        foreach (RolePermissionMatrix::map() as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($name !== 'Admin') {
                $role->permissions()->sync([]);
            }
        }
        $admin->permissions()->sync([]);
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'username' => 'admin-del-'.uniqid(),
            'email' => 'admin-del-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->roles()->sync([Role::where('name', 'Admin')->first()->id]);

        return $user;
    }

    public function test_an_admin_can_delete_a_user_even_with_roles_attached(): void
    {
        $admin = $this->makeAdmin();

        $victim = User::create([
            'name' => 'Victime',
            'username' => 'victim-'.uniqid(),
            'email' => 'victim-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'modules' => ['pos', 'orders'],
        ]);
        $victim->roles()->sync([Role::where('name', 'Caissier')->first()->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$victim->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
    }

    public function test_a_protected_account_cannot_be_deleted(): void
    {
        $admin = $this->makeAdmin();

        $protected = User::create([
            'name' => 'Protege',
            'username' => 'protege-'.uniqid(),
            'email' => 'protege-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
            'is_protected' => true,
            'modules' => ['pos'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/users/{$protected->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $protected->id]);
    }
}
