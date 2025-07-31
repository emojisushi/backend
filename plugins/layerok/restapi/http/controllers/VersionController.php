<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\RestApi\Models\AppVersionSettings;

class VersionController extends Controller
{
    public function fetchMobileVersion(): JsonResponse
    {
        $android = AppVersionSettings::get('android_version') ?? '0.0.1';
        $ios = AppVersionSettings::get('ios_version') ?? '0.0.1';
        $android_link = AppVersionSettings::get('android_link') ?? '';
        $ios_link = AppVersionSettings::get('ios_link') ?? '';

        return response()->json(['android' => $android, 'ios' => $ios, 'android_link' => $android_link, 'ios_link' => $ios_link]);
    }
}
