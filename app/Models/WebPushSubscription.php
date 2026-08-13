<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'content_encoding',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
