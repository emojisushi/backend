<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\PendingBonus;
use Layerok\PosterPos\Models\User;
use Layerok\RestApi\Models\ContactsSettings;

class ContactsController extends Controller
{
    public function contacts(): JsonResponse
    {
        $instagram_display_text = ContactsSettings::get('instagram_display_text');
        $instagram_app = ContactsSettings::get('instagram_app_link');
        $instagram_web = ContactsSettings::get('instagram_web_link');
        $telegram_display_text = ContactsSettings::get('telegram_display_text');
        $telegram_app = ContactsSettings::get('telegram_app_link');
        $telegram_web = ContactsSettings::get('telegram_web_link');

        return response()->json([
            'instagram_display_text' => $instagram_display_text,
            'instagram_app' => $instagram_app,
            'instagram_web' => $instagram_web,
            'telegram_display_text' => $telegram_display_text,
            'telegram_app' => $telegram_app,
            'telegram_web' => $telegram_web,
        ]);

    }
}