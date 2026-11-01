<?php

namespace Database\Seeders;

/**
 * Matrice des permissions par rôle, source unique utilisée par le seeder
 * et par la migration de synchronisation (safe à exécuter en production).
 */
class RolePermissionMatrix
{
    /**
     * @return array<string, array<int, string>|'*'>
     */
    public static function map(): array
    {
        return [
            'Admin' => '*',

            'Manager' => [
                'users.view', 'users.create', 'users.edit',
                'roles.view',
                'menu.view', 'menu.create', 'menu.edit', 'menu.delete',
                'zones.view', 'zones.create', 'zones.edit', 'zones.delete',
                'tables.view', 'tables.create', 'tables.edit', 'tables.delete',
                'orders.view', 'orders.create', 'orders.edit', 'orders.delete',
                'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.delete',
                'cash_registers.view', 'cash_registers.create', 'cash_registers.edit',
                'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
                'stock.view', 'stock.create', 'stock.edit', 'stock.delete',
                'reservations.view', 'reservations.create', 'reservations.edit', 'reservations.delete',
                'pos.view', 'pos.create',
                'reports.view',
                'settings.view', 'settings.edit',
            ],

            'Caissier' => [
                'pos.view', 'pos.create',
                'orders.view', 'orders.create', 'orders.edit',
                'invoices.view', 'invoices.create', 'invoices.edit',
                'clients.view', 'clients.create',
                'cash_registers.view', 'cash_registers.create', 'cash_registers.edit',
                'reports.view',
                'tables.view',
                'zones.view',
                'menu.view',
            ],

            'Serveur' => [
                'orders.view', 'orders.create', 'orders.edit',
                'tables.view', 'tables.edit',
                'menu.view',
                'clients.view', 'clients.create',
                'reservations.view', 'reservations.create', 'reservations.edit',
            ],

            'Cuisinier' => [
                'orders.view', 'orders.edit',
                'menu.view', 'menu.edit',
                'stock.view', 'stock.edit',
            ],
        ];
    }
}
