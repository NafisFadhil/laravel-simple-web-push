<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushController extends Controller
{
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
            ],
        );

        return response()->json([
            'ok' => true,
            'id' => $subscription->id,
        ]);
    }

    public function send(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:2048'],
        ]);

        $publicKey = config('webpush.vapid_public_key');
        $privateKey = config('webpush.vapid_private_key');
        $subject = config('webpush.vapid_subject');

        if (! is_string($publicKey) || $publicKey === '' || ! is_string($privateKey) || $privateKey === '' || ! is_string($subject) || $subject === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Missing VAPID configuration. Set VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, and VAPID_SUBJECT in .env',
            ], 500);
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $validated['title'] ?? 'Web Push',
            'body' => $validated['body'] ?? 'Hello from Laravel',
            'url' => $validated['url'] ?? url('/'),
        ], JSON_UNESCAPED_SLASHES);

        $subscriptions = PushSubscription::query()->latest('id')->get();

        if ($subscriptions->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'No subscriptions found. Click Aktifkan first.',
            ], 400);
        }

        $skipped = 0;
        $skippedEndpoints = [];

        foreach ($subscriptions as $sub) {
            // Safari uses Apple Push Notification service (APNs) with a different protocol.
            // This demo implements standard Web Push (VAPID) for Chrome/Edge/Firefox.
            if (str_starts_with($sub->endpoint, 'https://web.push.apple.com/')) {
                $skipped++;
                $skippedEndpoints[] = $sub->endpoint;
                continue;
            }

            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth,
                ]),
                $payload,
            );
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $endpoint = $report->getRequest()?->getUri()?->__toString();

            // Clean up expired/invalid subscriptions.
            $response = $report->getResponse();
            $statusCode = $response ? $response->getStatusCode() : null;
            if ($endpoint && ($statusCode === 404 || $statusCode === 410)) {
                PushSubscription::query()->where('endpoint', $endpoint)->delete();
            }

            $failed++;
            $errors[] = [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
                'statusCode' => $statusCode,
            ];
        }

        return response()->json([
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'skippedEndpoints' => $skippedEndpoints,
            'errors' => $errors,
        ]);
    }
}

