<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class CrmNotificationService
{
    public function notify(User $user, string $title, string $body, string $type = 'general', array $data = []): Notification
    {
        $notification = $user->notifications()->create([
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => $data,
            'is_sent' => false,
        ]);

        if ($this->sendPush($user, $notification)) {
            $notification->update(['is_sent' => true]);
        }

        return $notification->fresh();
    }

    private function sendPush(User $user, Notification $notification): bool
    {
        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');

        if (! $publicKey || ! $privateKey) {
            return false;
        }

        $subscriptions = $user->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return false;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $notification->title,
            'body' => $notification->body,
            'type' => $notification->type,
            'data' => $notification->data,
            'notification_id' => $notification->id,
            'url' => $notification->data['url'] ?? '/utilisateurs',
        ], JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification($this->toWebPushSubscription($subscription), $payload);
        }

        $sent = false;
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $subscription = $subscriptions->firstWhere('endpoint', $endpoint);

            if ($report->isSuccess()) {
                $sent = true;
                $subscription?->update(['last_used_at' => now()]);
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->delete();
                continue;
            }

            Log::warning('Failed to send web push notification.', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

    private function toWebPushSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => $subscription->content_encoding,
        ]);
    }
}
