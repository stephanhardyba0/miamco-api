<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /** Modules que l'API accepte d'enregistrer sur un compte. */
    /**
     * Perimetre donne quand ni modules ni roles ne sont fournis :
     * l'operationnel de base (pas de gestion, pas de reglages, pas de rapports)
     * — l'administrateur coche ce qu'il veut ensuite dans le formulaire.
     */
    private const DEFAULT_MODULES = [
        'dashboard', 'pos', 'orders', 'tables', 'zones', 'reservations', 'invoices',
        'cash_registers', 'menu', 'clients', 'stock',
    ];

    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles.permissions')
            ->when($request->get('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('username', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            }))
            ->when($request->get('role'), fn ($q, $role) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'nullable|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:255',
            // Plus de role obligatoire : l'acces se choisit par modules.
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:roles,id',
            'modules' => 'sometimes|nullable|array',
            'modules.*' => 'string|in:'.implode(',', User::MODULES),
            'is_active' => 'boolean',
        ]);

        $roles = $validated['roles'] ?? [];
        $modules = array_key_exists('modules', $validated) ? $validated['modules'] : null;

        // Ni modules ni roles : perimetre operatoire par defaut.
        if ($modules === null && $roles === []) {
            $modules = self::DEFAULT_MODULES;
        }

        /*
         * Unification : le role n'est plus choisi par l'administrateur,
         * il decoule de l'acces donne. Un perimetre complet = Admin
         * (bypass serveur + sections front reservees aux admins) ;
         * sinon aucun role — l'acces vit dans `users.modules`.
         */
        if ($modules !== null && array_key_exists('modules', $validated)) {
            $roles = $this->isFullAccess($modules) ? [$this->adminRoleId()] : [];
        }

        if ($roles !== []) {
            $this->assertCanAssignRoles($request, $roles);
        }

        $user = DB::transaction(function () use ($validated, $roles, $modules) {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'modules' => $modules,
            ]);

            if ($roles !== []) {
                $user->roles()->sync($roles);
            }

            $user->load('roles.permissions');

            return $user;
        });

        return response()->json(['message' => 'Utilisateur créé.', 'user' => new UserResource($user)], 201);
    }

    public function show($id): JsonResponse
    {
        $user = User::with('roles.permissions')->findOrFail($id);

        return response()->json(['user' => new UserResource($user)]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,'.$id,
            'email' => 'nullable|email|unique:users,email,'.$id,
            'password' => 'sometimes|string|min:8',
            'phone' => 'nullable|string|max:255',
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:roles,id',
            'modules' => 'sometimes|nullable|array',
            'modules.*' => 'string|in:'.implode(',', User::MODULES),
            'is_active' => 'sometimes|boolean',
            'is_protected' => 'sometimes|boolean',
        ]);

        $updateData = collect($validated)->except(['roles'])->toArray();

        if (array_key_exists('modules', $validated) && (int) $user->id === (int) $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier vos propres accès.',
            ], 422);
        }

        if (isset($validated['roles'])) {
            if ((int) $user->id === (int) $request->user()->id) {
                return response()->json([
                    'message' => 'Vous ne pouvez pas modifier vos propres rôles.',
                ], 422);
            }
            $this->assertCanAssignRoles($request, $validated['roles']);
        }

        if (isset($updateData['password'])) {
            $updateData['password'] = Hash::make($updateData['password']);
        }

        DB::transaction(function () use ($user, $updateData, $validated) {
            $user->update($updateData);

            if (array_key_exists('modules', $validated)) {
                // Meme regle que la creation : perimetre complet = Admin,
                // perimetre partiel = aucun role (acces pur par modules).
                $targetRoles = $this->isFullAccess($validated['modules'] ?? [])
                    ? [$this->adminRoleId()]
                    : [];
                $user->roles()->sync($targetRoles);
            } elseif (isset($validated['roles'])) {
                $user->roles()->sync($validated['roles']);
            }

            $user->load('roles.permissions');
        });

        return response()->json(['message' => 'Utilisateur mis à jour.', 'user' => new UserResource($user)]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Compte designé comme non supprimable (superadmin ou autre).
        if ($user->is_protected) {
            return response()->json([
                'message' => 'Compte protégé : suppression impossible.',
            ], 403);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        // `roles.id` qualifie : un pluck('id') nu sur cette jointure
        // (roles × user_role) est ambigu en MySQL et faisait echouer
        // TOUTE suppression d'utilisateur en 500.
        $this->assertCanAssignRoles($request, $user->roles()->pluck('roles.id')->all());

        DB::transaction(function () use ($user) {
            $user->roles()->detach();

            $user->orders()->update(['user_id' => null]);
            $user->cashRegisterSessions()->update(['user_id' => null]);

            $user->delete();
        });

        return response()->json(['message' => 'User deleted.']);
    }

    public function toggleActive(Request $request, $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 403);
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        $user->refresh();

        return response()->json(['message' => 'Statut mis à jour.', 'is_active' => $user->is_active]);
    }

    /**
     * Anti-escalade : seul un Admin peut attribuer le rôle Admin
     * (création, mise à jour, suppression de compte Admin).
     */
    /** L'utilisateur a-t-il (au moins) tous les modules autorisés ? */
    private function isFullAccess(array $modules): bool
    {
        return array_diff(User::MODULES, $modules) === [];
    }

    private function adminRoleId(): int
    {
        return (int) Role::where('name', 'Admin')->value('id');
    }

    private function assertCanAssignRoles(Request $request, array $roleIds): void
    {
        if ($request->user()?->hasRole('Admin')) {
            return;
        }

        $isAdminRoleAssigned = Role::whereIn('id', $roleIds)
            ->where('name', 'Admin')
            ->exists();

        if ($isAdminRoleAssigned) {
            abort(response()->json(['message' => 'Seul un administrateur peut attribuer le rôle Admin.'], 403));
        }
    }
}
