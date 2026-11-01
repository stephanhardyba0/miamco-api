<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\CashTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogActivity
{
    private const TRACKED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), self::TRACKED_METHODS, true)) {
            return $response;
        }

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $this->logActivity($request, $response);

        return $response;
    }

    private function logActivity(Request $request, Response $response): void
    {
        try {
            $user = $request->user();
            $action = $this->determineAction($request);
            $modelName = $this->extractModelName($request);

            if (! $modelName) {
                return;
            }

            AuditLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'model_type' => $modelName,
                'model_id' => $this->extractModelId($request, $response),
                'old_values' => null,
                'new_values' => $this->extractNewValues($request),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            report($e);
        }
    }

    private function determineAction(Request $request): string
    {
        return match ($request->method()) {
            'POST' => 'created',
            'PUT', 'PATCH' => 'updated',
            'DELETE' => 'deleted',
            default => 'unknown',
        };
    }

    private function extractModelName(Request $request): ?string
    {
        $segments = explode('/', trim($request->path(), '/'));

        $modelMap = [
            'users' => User::class,
            'roles' => Role::class,
            'permissions' => Permission::class,
            'orders' => Order::class,
            'invoices' => Invoice::class,
            'payments' => Payment::class,
            'menu' => MenuItem::class,
            'menu-items' => MenuItem::class,
            'menu-categories' => MenuCategory::class,
            'categories' => MenuCategory::class,
            'items' => MenuItem::class,
            'tables' => Table::class,
            'zones' => Zone::class,
            'clients' => Client::class,
            'stock-items' => StockItem::class,
            'stock-movements' => StockMovement::class,
            'reservations' => Reservation::class,
            'cash-registers' => CashRegister::class,
            'cash-register-sessions' => CashRegisterSession::class,
            'sessions' => CashRegisterSession::class,
            'cash-transactions' => CashTransaction::class,
        ];

        // Combine nested resource segments first (e.g. "menu/items" -> "menu-items"),
        // then fall back to single-segment lookup anywhere in the path.
        for ($i = 0; $i < count($segments) - 1; $i++) {
            $combined = $segments[$i].'-'.$segments[$i + 1];
            if (isset($modelMap[$combined])) {
                return $modelMap[$combined];
            }
        }

        foreach ($segments as $segment) {
            if (isset($modelMap[$segment])) {
                return $modelMap[$segment];
            }
        }

        return null;
    }

    private function extractModelId(Request $request, Response $response): ?int
    {
        $parameters = $request->route()?->parameters() ?? [];
        foreach ($parameters as $value) {
            if (is_scalar($value) && is_numeric($value)) {
                return (int) $value;
            }
        }

        $payload = null;
        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);
        } else {
            $content = $response->getContent();
            $payload = is_string($content) ? json_decode($content, true) : null;
        }

        if (is_array($payload)) {
            foreach ($payload as $value) {
                if (is_array($value) && isset($value['id']) && is_numeric($value['id'])) {
                    return (int) $value['id'];
                }
            }
            if (isset($payload['id']) && is_numeric($payload['id'])) {
                return (int) $payload['id'];
            }
        }

        return null;
    }

    private function extractNewValues(Request $request): ?array
    {
        $data = $request->except([
            'password',
            'password_confirmation',
            'current_password',
            'pin',
            'pin_hash',
            'token',
            'plainTextToken',
            'secret',
            'api_key',
        ]);

        return ! empty($data) ? $data : null;
    }
}
