<?php

namespace Layerok\Restapi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Layerok\PosterPos\Models\SmsConfirmation;
use Illuminate\Support\Str;

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

        $code = random_int(100000, 999999);

        $confirmation = SmsConfirmation::updateOrCreate(
            ['phone' => $phone],
            ['last_code' => $code, 'confirmed' => false]
        );

        // e.g. SmsService::send($phone, "Your code is: {$code}");

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
