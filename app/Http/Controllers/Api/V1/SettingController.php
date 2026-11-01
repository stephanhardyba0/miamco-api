<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $settings = Setting::all();

            return response()->json(['settings' => $settings]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la récupération des paramètres.'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'settings' => 'required|array|min:1',
                'settings.*.key' => 'required|string',
                'settings.*.value' => 'present|string',
            ]);

            foreach ($request->settings as $item) {
                Setting::set($item['key'], $item['value']);
            }

            $settings = Setting::all();

            return response()->json([
                'message' => 'Paramètres mis à jour avec succès.',
                'settings' => $settings,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Erreur de validation.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Erreur lors de la mise à jour des paramètres.'], 500);
        }
    }
}
