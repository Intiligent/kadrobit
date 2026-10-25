<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateUserSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's own settings (App\Enums\UserSettingKey). Both actions answer with every setting, defaults filled in.
 */
class UserSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->settingValues()]);
    }

    public function update(UpdateUserSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        foreach ($request->changes() as [$setting, $value]) {
            $user->setSetting($setting, $value);
        }

        return response()->json(['data' => $user->settingValues()]);
    }
}
