<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModulePermission
{
    /**
     * Actions métier dont le verbe HTTP ne reflète pas l'action CRUD
     * (POST /tables/{id}/occupy est une édition, pas une création).
     */
    private const EDIT_ACTIONS = [
        'toggle-active',
        'toggle-availability',
        'occupy',
        'reserve',
        'release',
        'pay',
        'unpay',
        'open',
        'close',
        'reopen',
        'serve',
        'cancel',
        'status',
        'sync-permissions',
        'permissions',
    ];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $action = $this->resolveAction($request);
        $permission = "{$module}.{$action}";

        /*
         * Deux regimes d'acces cohabitent :
         * - `users.modules` renseigne (unification : plus de role a preciser)
         *   → acces binaire au module ; l'utilisateur qui a « Caisse » a
         *     tout le module, sans distinction view/create/edit/delete ;
         * - `modules` null (anciens comptes) → granularite historique
         *   `module.action` via les bundles de role, inchangee.
         * Admin : autorise dans les deux cas (hasRole ici et dans le modele).
         */
        if ($user->modules !== null) {
            if (! $user->canAccessModule($module)) {
                return response()->json([
                    'message' => 'Accès interdit.',
                    'required_module' => $module,
                ], 403);
            }

            return $next($request);
        }

        if (! $user->hasPermission($permission)) {
            return response()->json([
                'message' => 'Accès interdit.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }

    private function resolveAction(Request $request): string
    {
        $segments = array_values(array_filter(explode('/', trim($request->path(), '/'))));
        $last = end($segments) ?: '';

        return match ($request->method()) {
            'GET' => 'view',
            'DELETE' => 'delete',
            'POST' => in_array($last, self::EDIT_ACTIONS, true) ? 'edit' : 'create',
            'PUT', 'PATCH' => 'edit',
            default => 'view',
        };
    }
}
