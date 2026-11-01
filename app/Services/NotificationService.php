<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class NotificationService
{
    public static function notifyUser(
        int $userId,
        string $title,
        string $message,
        string $type,
        ?string $icon = null,
        string $color = 'primary',
        ?string $actionUrl = null,
        ?array $metadata = null,
        bool $isImportant = false,
    ): AppNotification {
        $notification = AppNotification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'icon' => $icon,
            'color' => $color,
            'is_important' => $isImportant,
            'action_url' => $actionUrl,
            'metadata' => $metadata,
        ]);

        NotificationCreated::dispatch($notification);

        return $notification;
    }

    public static function notifyRole(
        string $roleName,
        string $title,
        string $message,
        string $type,
        ?string $icon = null,
        string $color = 'primary',
        ?string $actionUrl = null,
        ?array $metadata = null,
        bool $isImportant = false,
    ): Collection {
        $users = User::whereHas('roles', fn ($q) => $q->where('name', $roleName))->get();

        return $users->map(fn (User $user) => static::notifyUser(
            $user->id,
            $title,
            $message,
            $type,
            $icon,
            $color,
            $actionUrl,
            $metadata,
            $isImportant,
        ));
    }

    public static function notifyRoles(
        array $roleNames,
        string $title,
        string $message,
        string $type,
        ?string $icon = null,
        string $color = 'primary',
        ?string $actionUrl = null,
        ?array $metadata = null,
        bool $isImportant = false,
        ?string $module = null,
    ): Collection {
        /*
         * Deux regimes cohabitent : les comptes a roles (historique) et
         * les comptes unifiés dont l'acces vit dans `users.modules` —
         * sans ce second envoi, un utilisateur ayant le module Commandes
         * ne recevait AUCUNE notification de commande.
         */
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', $roleNames))->get();

        if ($module !== null) {
            $moduleUsers = User::whereNotNull('modules')
                ->get()
                ->filter(fn (User $user) => in_array($module, $user->modules ?? [], true));

            $users = $users->concat($moduleUsers)->unique('id')->values();
        }

        return $users->map(fn (User $user) => static::notifyUser(
            $user->id,
            $title,
            $message,
            $type,
            $icon,
            $color,
            $actionUrl,
            $metadata,
            $isImportant,
        ));
    }

    public static function notifyAdmins(
        string $title,
        string $message,
        string $type,
        ?string $icon = null,
        string $color = 'primary',
        ?string $actionUrl = null,
        ?array $metadata = null,
        bool $isImportant = false,
    ): Collection {
        return static::notifyRoles(
            ['Admin', 'Manager'],
            $title,
            $message,
            $type,
            $icon,
            $color,
            $actionUrl,
            $metadata,
            $isImportant,
            'users',
        );
    }

    public static function markAsRead(int $notificationId, int $userId): bool
    {
        return (bool) AppNotification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->update(['is_read' => true]);
    }

    public static function markAllAsRead(int $userId): int
    {
        return AppNotification::where('user_id', $userId)
            ->unread()
            ->update(['is_read' => true]);
    }

    public static function getUnreadCount(int $userId): int
    {
        return AppNotification::where('user_id', $userId)->unread()->count();
    }

    public static function getNotifications(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return AppNotification::where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }
}
