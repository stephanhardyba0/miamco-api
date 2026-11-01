<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json($roles);
    }

    public function show($id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json(['role' => $role]);
    }
}
