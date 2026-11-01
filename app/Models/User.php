<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /**
     * Modules consideres par l'application — source unique de verite
     * (validation API, plafonnement des droits, suppression factures).
     */
    public const MODULES = [
        'dashboard', 'pos', 'orders', 'tables', 'zones', 'reservations', 'invoices',
        'cash_registers', 'menu', 'clients', 'stock', 'reports', 'users', 'settings',
    ];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'phone',
        'is_active',
        'is_protected',
        'modules',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin',
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_protected' => 'boolean',
            // Liste des modules accessibles ; null = ancien compte (role).
            'modules' => 'array',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cashRegisterSessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->hasRole('Admin')) {
            return true;
        }

        // Roles figes (option A) : plus de permissions individuelles par compte.
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->exists();
    }

    /**
     * Acces binaire a un module, sans notion de role a preciser :
     *
     * - Admin : tout ;
     * - `modules` renseigne (comptes crees depuis l'unification) :
     *   le module est dedans ou non ;
     * - `modules` null (anciens comptes / tests) : repli sur les
     *   bundles du role, comportement historique inchange.
     */
    /**
     * Acces complet = les 14 modules (ou le role Admin, historique).
     * Seul ce niveau peut realiser les operations a fort risque
     * (suppression de factures...).
     */
    public function hasFullAccess(): bool
    {
        if ($this->hasRole('Admin')) {
            return true;
        }

        return $this->modules !== null && array_diff(self::MODULES, $this->modules) === [];
    }

    public function canAccessModule(string $module): bool
    {
        if ($this->hasRole('Admin')) {
            return true;
        }

        if ($this->modules !== null) {
            return in_array($module, $this->modules, true);
        }

        return $this->hasPermission("{$module}.view");
    }

    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->hasRole('Admin')) {
            return true;
        }

        foreach ($permissions as $perm) {
            if ($this->hasPermission($perm)) {
                return true;
            }
        }

        return false;
    }

    public function scopeWithPermission($query, string $permission)
    {
        return $query->whereHas('roles.permissions', fn ($q) => $q->where('permissions.name', $permission));
    }
}
