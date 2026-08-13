<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

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

        if ($this->sendPushSafely($user, $notification)) {
            $notification->update(['is_sent' => true]);
        }

        return $notification->fresh();
    }

    public function subscriptionCounts(User $user): array
    {
        return [
            'crm' => $user->pushSubscriptions()->count(),
            'mobile_web' => $this->legacyWebPushSubscriptions($user)->count(),
        ];
    }

    private function sendPush(User $user, Notification $notification): bool
    {
        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');

        if (! $publicKey || ! $privateKey) {
            return false;
        }

        $subscriptions = $user->pushSubscriptions()->get();
        $legacySubscriptions = $this->legacyWebPushSubscriptions($user);
        if ($subscriptions->isEmpty() && $legacySubscriptions->isEmpty()) {
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

        foreach ($legacySubscriptions as $subscription) {
            $webPush->queueNotification($this->toLegacyWebPushSubscription($subscription), $payload);
        }

        $sent = false;
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $subscription = $subscriptions->firstWhere('endpoint', $endpoint);
            $legacySubscription = $legacySubscriptions->firstWhere('endpoint', $endpoint);

            if ($report->isSuccess()) {
                $sent = true;
                $subscription?->update(['last_used_at' => now()]);
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->delete();
                $legacySubscription?->delete();
                continue;
            }

            Log::warning('Failed to send web push notification.', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

    private function sendPushSafely(User $user, Notification $notification): bool
    {
        try {
            return $this->sendPush($user, $notification);
        } catch (Throwable $exception) {
            Log::warning('Failed to send web push notification.', [
                'user_id' => $user->id,
                'notification_id' => $notification->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
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

    private function legacyWebPushSubscriptions(User $user)
    {
        if (! Schema::hasTable('web_push_subscriptions')) {
            return collect();
        }

        return WebPushSubscription::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();
    }

    private function toLegacyWebPushSubscription(WebPushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'keys' => [
                'p256dh' => $subscription->p256dh,
                'auth' => $subscription->auth,
            ],
            'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
        ]);
    }
}
