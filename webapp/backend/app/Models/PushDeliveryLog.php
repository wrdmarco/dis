<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class PushDeliveryLog extends Model
{
    use UsesUlids;

    protected $fillable = ['user_id', 'fcm_token_id', 'dispatch_request_id', 'message_type', 'status', 'provider_message_id', 'error_code', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}

