<?php

namespace Database\Seeders;

use App\Models\CashRegister;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->createPermissions();
        $roles = $this->createRoles();
        $this->createAdminUser($roles);
        $this->createZones();
        $this->createCashRegister();
        $this->createSettings();
        $this->call(MenuSeeder::class);
    }

    private function createPermissions(): void
    {
        $modules = [
            'users' => ['view', 'create', 'edit', 'delete'],
            'roles' => ['view', 'create', 'edit', 'delete'],
            'menu' => ['view', 'create', 'edit', 'delete'],
            'zones' => ['view', 'create', 'edit', 'delete'],
            'tables' => ['view', 'create', 'edit', 'delete'],
            'orders' => ['view', 'create', 'edit', 'delete'],
            'invoices' => ['view', 'create', 'edit', 'delete'],
            'cash_registers' => ['view', 'create', 'edit', 'delete'],
            'clients' => ['view', 'create', 'edit', 'delete'],
            'stock' => ['view', 'create', 'edit', 'delete'],
            'settings' => ['view', 'edit'],
            'reports' => ['view'],
            'reservations' => ['view', 'create', 'edit', 'delete'],
            'pos' => ['view', 'create'],
        ];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$module}.{$action}"],
                    [
                        'module' => $module,
                        'action' => $action,
                        'guard_name' => 'web',
                    ]
                );
            }
        }
    }

    private function createRoles(): array
    {
        $allPermissions = Permission::pluck('id')->toArray();

        $createdRoles = [];

        foreach (RolePermissionMatrix::map() as $name => $permissionNames) {
            $permissionIds = $permissionNames === '*'
                ? $allPermissions
                : Permission::whereIn('name', $permissionNames)->pluck('id')->toArray();

            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $this->roleDescription($name)]
            );
            $role->permissions()->sync($permissionIds);
            $createdRoles[$name] = $role;
        }

        return $createdRoles;
    }

    private function roleDescription(string $role): string
    {
        return match ($role) {
            'Admin' => 'Administrateur avec tous les droits',
            'Manager' => 'Gestionnaire du restaurant',
            'Caissier' => 'Caissier - gestion des paiements et factures',
            'Serveur' => 'Serveur - prise de commandes et réservations',
            'Cuisinier' => 'Cuisinier - préparation des commandes',
            default => '',
        };
    }

    private function createAdminUser(array $roles): void
    {
        $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(24);

        $admin = User::firstOrCreate(
            ['email' => 'webmaster@miamcocg.site'],
            [
                'name' => 'Admin',
                'username' => 'webmaster',
                'password' => Hash::make($password),
                'phone' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->roles()->sync([$roles['Admin']->id]);

        if ($admin->wasRecentlyCreated) {
            $this->command?->warn("Compte admin créé — mot de passe (à changer immédiatement) : {$password}");
        }
    }

    private function createZones(): void
    {
        $zones = ['Salle', 'Terrasse', 'Bar', 'Salon VIP'];

        foreach ($zones as $name) {
            Zone::firstOrCreate(['name' => $name]);
        }
    }

    private function createCashRegister(): void
    {
        CashRegister::firstOrCreate(
            ['name' => 'Caisse Principale'],
            ['is_open' => false, 'current_balance' => 0]
        );
    }

    private function createSettings(): void
    {
        $settings = [
            ['key' => 'restaurant_name', 'value' => 'Miam & Co', 'group' => 'general'],
            ['key' => 'restaurant_address', 'value' => '', 'group' => 'general'],
            ['key' => 'restaurant_phone', 'value' => '', 'group' => 'general'],
            ['key' => 'currency', 'value' => 'XAF', 'group' => 'general'],
            ['key' => 'currency_symbol', 'value' => 'FCFA', 'group' => 'general'],
            ['key' => 'tax_rate', 'value' => '0', 'group' => 'finance'],
            ['key' => 'receipt_header', 'value' => 'Miam & Co', 'group' => 'receipt'],
            ['key' => 'receipt_subheader', 'value' => 'Restaurant & Bar', 'group' => 'receipt'],
            ['key' => 'receipt_address', 'value' => '', 'group' => 'receipt'],
            ['key' => 'receipt_phone', 'value' => '', 'group' => 'receipt'],
            ['key' => 'receipt_footer', 'value' => 'Merci de votre visite !', 'group' => 'receipt'],
            ['key' => 'receipt_footer2', 'value' => 'À très bientôt !', 'group' => 'receipt'],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'group' => $setting['group']]
            );
        }
    }
}
