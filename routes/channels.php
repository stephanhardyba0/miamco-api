<?php

use Illuminate\Support\Facades\Broadcast;

// Authentification des canaux privés par token Sanctum (SPA / mobile).
Broadcast::routes(['middleware' => ['auth:sanctum']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal de l'établissement : tous les écrans connectés (cuisine, serveur, caisse)
// reçoivent les mises à jour de commandes et de tables.
Broadcast::channel('restaurant', function ($user) {
    return (bool) $user;
});
