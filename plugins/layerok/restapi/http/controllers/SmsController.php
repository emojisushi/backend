<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\SmsConfirmation;
use Illuminate\Support\Facades\Http;

class SmsController extends Controller
{
    public function checkPhone(Request $request)
    {
        $phone = $request->input('phone');

        $confirmation = SmsConfirmation::where('phone', $phone)->first();

        if (!$confirmation) {
            return response()->json([
                'confirmed' => false,
                'message' => 'Phone number not found.'
            ], 404);
        }

        return response()->json([
            'confirmed' => (bool) $confirmation->confirmed
        ]);
    }

    public function generateCode(Request $request)
    {
        $phone = $request->input('phone');
        $city_slug = $request->input('city_slug');
        $domain = "$city_slug.emojisushi.com.ua";
        // $domain = request()->getHost();

        $code = random_int(100000, 999999);

        $confirmation = SmsConfirmation::updateOrCreate(
            ['phone' => $phone],
            ['last_code' => $code, 'confirmed' => false]
        );

        // $sms = new TurboSMS();
        // $sended = $sms->sendMessages($phone, "Vash kod: $code");
        $username = config('sms.inteltele_username');
        $apiKey = config('sms.inteltele_api_key');
        $sender = config('sms.inteltele_sender');
        $phoneSanitaized = preg_replace('/[^0-9]/', '', $phone); // sanitize
        $url = 'http://api.sms.intel-tele.com/message/send/';

        $response = Http::asForm()->get($url, [
            'username' => $username,
            'api_key' => $apiKey,
            'from' => $sender,
            'to' => $phoneSanitaized,
            'priority' => 3,
            'message' => "Vash kod: {$code}\n\n@$domain #$code",
        ]);

        return true;
    }

    public function checkCode(Request $request)
    {
        $phone = $request->input('phone');
        $code = $request->input('code');

        $confirmation = SmsConfirmation::where('phone', $phone)->first();

        if (!$confirmation) {
            return response()->json([
                'success' => false,
                'message' => 'Phone number not found.'
            ], 404);
        }

        if ($confirmation->last_code == $code) {
            $confirmation->confirmed = true;
            $confirmation->save();

            return response()->json([
                'success' => true,
                'message' => 'Phone confirmed successfully.'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Incorrect code.'
        ], 400);
    }
}
