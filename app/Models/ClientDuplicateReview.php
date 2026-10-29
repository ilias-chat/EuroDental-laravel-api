<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientDuplicateReview extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['clients' => 'array', 'reasons' => 'array', 'reviewed_at' => 'datetime'];
}
