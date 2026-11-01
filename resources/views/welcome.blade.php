<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Miam & Co POS — API</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                darkMode: 'media',
            }
        </script>
        <style>
            body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; }
        </style>
    </head>
    <body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC] min-h-screen">
        <div class="min-h-screen flex flex-col">
            <header class="w-full border-b border-[#e3e3e0] dark:border-[#1E1E1C]">
                <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-[#8A5A30] rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18v18H3zM3 9h18M9 21V9" />
                            </svg>
                        </div>
                        <span class="text-lg font-semibold tracking-tight">Miam & Co POS</span>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <span class="px-2.5 py-0.5 rounded-full bg-[#8A5A30]/10 text-[#8A5A30] dark:bg-[#8A5A30]/20 font-medium text-xs">v1</span>
                        <span class="text-[#706f6c] dark:text-[#A1A09A]">Laravel {{ app()->version() }}</span>
                    </div>
                </div>
            </header>

            <main class="flex-1">
                <section class="max-w-6xl mx-auto px-6 py-16 lg:py-24">
                    <div class="max-w-2xl">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#8A5A30]/10 dark:bg-[#8A5A30]/15 text-[#8A5A30] text-xs font-medium mb-6">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#8A5A30]"></span>
                            API REST — Sanctum
                        </div>
                        <h1 class="text-4xl lg:text-5xl font-semibold tracking-tight mb-4 leading-tight">
                            Miam & Co<br>
                            <span class="text-[#8A5A30]">Point of Sale</span> API
                        </h1>
                        <p class="text-lg text-[#706f6c] dark:text-[#A1A09A] mb-8 leading-relaxed">
                            API REST complète pour la gestion de votre restaurant : commandes, factures, caisse, stock, réservations, cuisine et plus encore.
                        </p>
                        <div class="flex flex-wrap gap-3">
                            <code class="inline-flex items-center gap-2 px-4 py-2.5 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#2A2A28] rounded-lg text-sm font-mono text-[#706f6c] dark:text-[#A1A09A] shadow-sm">
                                <span class="text-[#8A5A30]">$</span> Base URL
                                <span class="text-[#1b1b18] dark:text-[#EDEDEC]">{{ config('app.url') }}/api/v1</span>
                            </code>
                        </div>
                    </div>
                </section>

                <section class="border-t border-[#e3e3e0] dark:border-[#1E1E1C]">
                    <div class="max-w-6xl mx-auto px-6 py-12 lg:py-16">
                        <h2 class="text-xs font-semibold uppercase tracking-widest text-[#706f6c] dark:text-[#A1A09A] mb-8">Authentification</h2>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="p-5 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#2A2A28] rounded-xl">
                                <div class="flex items-center gap-3 mb-3">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                    </span>
                                    <h3 class="font-semibold">Login</h3>
                                </div>
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="px-1.5 py-0.5 text-[10px] font-bold rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">POST</span>
                                    <code class="text-xs font-mono text-[#706f6c] dark:text-[#A1A09A]">/auth/login</code>
                                </div>
                                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Connexion par email et mot de passe. Retourne un token Sanctum.</p>
                            </div>
                            <div class="p-5 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#2A2A28] rounded-xl">
                                <div class="flex items-center gap-3 mb-3">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" /></svg>
                                    </span>
                                    <h3 class="font-semibold">Changement de mot de passe</h3>
                                </div>
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="px-1.5 py-0.5 text-[10px] font-bold rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">PUT</span>
                                    <code class="text-xs font-mono text-[#706f6c] dark:text-[#A1A09A]">/auth/password</code>
                                </div>
                                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Change le mot de passe et déconnecte les autres appareils.</p>
                            </div>
                        </div>
                        <div class="mt-4 p-4 bg-[#8A5A30]/5 dark:bg-[#8A5A30]/5 border border-[#8A5A30]/20 rounded-lg">
                            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                <span class="font-semibold text-[#8A5A30]">Headers requis :</span>
                                <code class="text-xs font-mono bg-white/50 dark:bg-black/20 px-1.5 py-0.5 rounded ml-1">Authorization: Bearer {token}</code>
                                <span class="mx-2">&mdash;</span>
                                <code class="text-xs font-mono bg-white/50 dark:bg-black/20 px-1.5 py-0.5 rounded">Accept: application/json</code>
                            </p>
                        </div>
                    </div>
                </section>

                <section class="border-t border-[#e3e3e0] dark:border-[#1E1E1C]">
                    <div class="max-w-6xl mx-auto px-6 py-12 lg:py-16">
                        <h2 class="text-xs font-semibold uppercase tracking-widest text-[#706f6c] dark:text-[#A1A09A] mb-8">Modules de l'API</h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @php
                                $modules = [
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />',
                                        'name' => 'Commandes',
                                        'route' => '/orders',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Gestion des commandes, changement de statut, annulation et service.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />',
                                        'name' => 'Factures',
                                        'route' => '/invoices',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Facturation, paiement et annulation de paiements.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />',
                                        'name' => 'Caisse',
                                        'route' => '/cash-registers',
                                        'verbs' => ['GET', 'POST'],
                                        'desc' => 'Ouverture, fermeture de caisse, sessions et transactions.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />',
                                        'name' => 'Menu',
                                        'route' => '/menu/categories, /menu/items',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Catégories et articles du menu, gestion de la disponibilité.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />',
                                        'name' => 'Tables & Zones',
                                        'route' => '/tables, /zones',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Gestion des zones, tables, occupation et réservation.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />',
                                        'name' => 'Réservations',
                                        'route' => '/reservations',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Création, confirmation et annulation de réservations.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />',
                                        'name' => 'Cuisine',
                                        'route' => '/kitchen/orders',
                                        'verbs' => ['GET', 'POST'],
                                        'desc' => 'File d\'attente cuisine, démarrage préparation et signaler prêt.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />',
                                        'name' => 'Stock',
                                        'route' => '/stock-items, /stock-movements',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Articles en stock, mouvements et alertes de réapprovisionnement.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />',
                                        'name' => 'Clients',
                                        'route' => '/clients',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Gestion des clients, activation/désactivation.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />',
                                        'name' => 'Fournisseurs',
                                        'route' => '/suppliers, /purchase-orders',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Fournisseurs, bons de commande et réception de marchandise.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />',
                                        'name' => 'Utilisateurs',
                                        'route' => '/users',
                                        'verbs' => ['GET', 'POST', 'PUT', 'DELETE'],
                                        'desc' => 'Gestion des utilisateurs, rôles et permissions.',
                                    ],
                                    [
                                        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
                                        'name' => 'Paramètres',
                                        'route' => '/settings',
                                        'verbs' => ['GET', 'PUT'],
                                        'desc' => 'Configuration générale du système et du restaurant.',
                                    ],
                                ];
                            @endphp

                            @foreach ($modules as $module)
                                <div class="group p-5 bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#2A2A28] rounded-xl hover:border-[#8A5A30]/40 dark:hover:border-[#8A5A30]/30 transition-colors">
                                    <div class="flex items-start justify-between mb-3">
                                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-[#FDFDFC] dark:bg-[#0a0a0a] border border-[#e3e3e0] dark:border-[#2A2A28] text-[#706f6c] dark:text-[#A1A09A] group-hover:text-[#8A5A30] group-hover:border-[#8A5A30]/30 transition-colors">
                                            <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">{!! $module['icon'] !!}</svg>
                                        </span>
                                        <div class="flex gap-1">
                                            @foreach ($module['verbs'] as $verb)
                                                @php
                                                    $colors = [
                                                        'GET' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                                                        'POST' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                                                        'PUT' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                                        'DELETE' => 'bg-red-500/10 text-red-600 dark:text-red-400',
                                                    ];
                                                @endphp
                                                <span class="px-1.5 py-0.5 text-[9px] font-bold rounded {{ $colors[$verb] }}">{{ $verb }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                    <h3 class="font-semibold text-sm mb-1">{{ $module['name'] }}</h3>
                                    <code class="text-[11px] font-mono text-[#8A5A30] block mb-2">{{ $module['route'] }}</code>
                                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A] leading-relaxed">{{ $module['desc'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="border-t border-[#e3e3e0] dark:border-[#1E1E1C]">
                    <div class="max-w-6xl mx-auto px-6 py-12 lg:py-16">
                        <h2 class="text-xs font-semibold uppercase tracking-widest text-[#706f6c] dark:text-[#A1A09A] mb-8">Réponse standard</h2>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div>
                                <h3 class="text-sm font-semibold mb-3 text-emerald-600 dark:text-emerald-400">Succès</h3>
                                <pre class="p-4 bg-[#161615] rounded-xl text-xs leading-relaxed overflow-x-auto"><code class="text-[#A1A09A]">{
    <span class="text-emerald-400">"success"</span>: <span class="text-amber-300">true</span>,
    <span class="text-emerald-400">"data"</span>: { ... },
    <span class="text-emerald-400">"message"</span>: <span class="text-emerald-300">"Opération réussie"</span>
}</code></pre>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold mb-3 text-red-500 dark:text-red-400">Erreur</h3>
                                <pre class="p-4 bg-[#161615] rounded-xl text-xs leading-relaxed overflow-x-auto"><code class="text-[#A1A09A]">{
    <span class="text-emerald-400">"success"</span>: <span class="text-red-400">false</span>,
    <span class="text-emerald-400">"message"</span>: <span class="text-emerald-300">"Erreur de validation"</span>,
    <span class="text-emerald-400">"errors"</span>: { ... }
}</code></pre>
                            </div>
                        </div>

                        <h2 class="text-xs font-semibold uppercase tracking-widest text-[#706f6c] dark:text-[#A1A09A] mb-4 mt-10">Codes de statut HTTP</h2>
                        <div class="flex flex-wrap gap-2">
                            @php
                                $statusCodes = [
                                    ['200', 'OK', 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
                                    ['201', 'Créé', 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'],
                                    ['204', 'Supprimé', 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
                                    ['401', 'Non authentifié', 'bg-amber-500/10 text-amber-600 dark:text-amber-400'],
                                    ['403', 'Non autorisé', 'bg-amber-500/10 text-amber-600 dark:text-amber-400'],
                                    ['404', 'Introuvable', 'bg-amber-500/10 text-amber-600 dark:text-amber-400'],
                                    ['422', 'Validation', 'bg-red-500/10 text-red-600 dark:text-red-400'],
                                    ['429', 'Rate limit', 'bg-red-500/10 text-red-600 dark:text-red-400'],
                                ];
                            @endphp
                            @foreach ($statusCodes as $code)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg {{ $code[2] }} text-xs font-medium">
                                    <span class="font-bold">{{ $code[0] }}</span> {{ $code[1] }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                </section>
            </main>

            <footer class="border-t border-[#e3e3e0] dark:border-[#1E1E1C]">
                <div class="max-w-6xl mx-auto px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    <span>Miam & Co POS API — Laravel {{ app()->version() }}</span>
                    <span>PHP {{ PHP_VERSION }} · Sanctum · SQLite</span>
                </div>
            </footer>
        </div>
    </body>
</html>