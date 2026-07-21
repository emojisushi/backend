<?php

namespace Layerok\Restapi\Services;

use Illuminate\Http\Request;
use Layerok\PosterPos\Models\SmsConfirmation;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Layerok\PosterPos\Models\FcmToken;

class FcmService
{
    protected string $projectId;
    protected string $accessToken;
    protected Client $http;

    public function __construct()
    {
        $serviceAccount = json_decode(
            file_get_contents(storage_path('app/firebase-service-account.json')),
            true
        );

        $this->projectId = $serviceAccount['project_id'];
        $this->accessToken = $this->getAccessToken();
        $this->http = new Client();
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        try {
            $message = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
            ];

            if (!empty($data)) {
                $message['data'] = array_map('strval', $data);
            }

            $response = $this->http->post(
                "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$this->accessToken}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'message' => $message,
                    ],
                ]
            );
            \Log::info('FCM success', [
                'body' => $response->getBody()->getContents()
            ]);
            FcmToken::where('fcm_token', $token)
                ->update(['last_used_at' => now()]);

            return true;
        } catch (ClientException $e) {
            $this->handleFcmError($token, $e);
            return false;
        }
    }

    public function sendToAll(string $title, string $body, array $data = []): void
    {
        $tokens = FcmToken::pluck('fcm_token')->toArray();
        $this->sendToMultipleTokens($tokens, $title, $body, $data);
    }

    public function sendToMultipleTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        // FCM v1 has no batch endpoint
        foreach ($tokens as $token) {
            $this->sendToToken($token, $title, $body, $data);
        }
    }

    private function handleFcmError(string $token, ClientException $e): void
    {
        $responseBody = $e->getResponse()->getBody()->getContents();

        // \Log::error('FCM RESPONSE', [
        //     'token' => $token,
        //     'body' => $responseBody,
        // ]);

        $body = json_decode($responseBody, true);
        $status  = $body['error']['status'] ?? '';
        $details = $body['error']['details'] ?? [];

        // Some errors are nested in details
        $errorCode = $status;
        foreach ($details as $detail) {
            if (isset($detail['errorCode'])) {
                $errorCode = $detail['errorCode'];
                break;
            }
        }

        $invalidTokenErrors = [
            'UNREGISTERED',    // app uninstalled
            'INVALID_ARGUMENT', // malformed token
            'NOT_FOUND',        // token doesn't exist
        ];

        if (in_array($errorCode, $invalidTokenErrors)) {
            // Token is dead — delete it
            FcmToken::where('fcm_token', $token)->delete();
            //\Log::info("FCM: deleted invalid token [{$errorCode}]");
        } else {
            // Log other errors (QUOTA_EXCEEDED, INTERNAL, etc.) but keep token
            \Log::error("FCM: failed to send [{$errorCode}]", ['token' => $token]);
        }
    }

    private function getAccessToken(): string
    {
        $path = base_path(env('FIREBASE_CREDENTIALS'));

        $serviceAccount = json_decode(
            file_get_contents($path),
            true
        );

        $now = time();

        // Build JWT header + payload
        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss'   => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $unsignedJwt = "{$header}.{$payload}";

        // Sign with private key
        openssl_sign($unsignedJwt, $signature, $serviceAccount['private_key'], 'SHA256');
        $jwt = "{$unsignedJwt}." . base64_encode($signature);

        // Exchange JWT for access token
        $response = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]),
            ],
        ]));

        return json_decode($response, true)['access_token'];
    }
}
