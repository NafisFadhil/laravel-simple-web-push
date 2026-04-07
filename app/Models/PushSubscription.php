<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'endpoint',
    'p256dh',
    'auth',
])]
class PushSubscription extends Model
{
    use HasFactory;
}

