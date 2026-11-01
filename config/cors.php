<?php

return [
    // `broadcasting/auth` est en dehors de `api/*` (Broadcast::routes) :
    // sans lui ici, Echo/Reverb reçoit un 200 sans en-tête CORS et le
    // navigateur bloque l'authentification des canaux privés.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'https://app.miamcocg.site,http://localhost:3000,http://127.0.0.1:3000'
    ))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
