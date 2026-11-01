<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\StatusController;

Route::get('/', [StatusController::class, 'index'])->middleware('cache.headers:no_store;private;max_age=0');
Route::get('/docs', function () {
    return view('welcome');
});
