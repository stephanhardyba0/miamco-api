<?php

namespace App\Http\Middleware;

use App\Enums\CashRegisterSessionStatus;
use App\Models\CashRegisterSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOpenCashRegister
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $hasOpenSession = CashRegisterSession::where('user_id', $user->id)
            ->where('status', CashRegisterSessionStatus::Open)
            ->exists();

        if (! $hasOpenSession) {
            return response()->json([
                'message' => 'Aucune caisse ouverte. Veuillez ouvrir une caisse avant de continuer.',
            ], 403);
        }

        return $next($request);
    }
}
