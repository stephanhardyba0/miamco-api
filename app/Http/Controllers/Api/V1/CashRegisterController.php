<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;

class CashRegisterController extends Controller
{
    public function index(): JsonResponse
    {
        $registers = CashRegister::withCount('sessions')->get();

        return response()->json($registers);
    }
}
