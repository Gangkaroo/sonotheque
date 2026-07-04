<?php

namespace App\Models;

use App\Enums\OnlineContentStatus;
use App\Enums\OnlineContentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'resource_type',
    'lookup_hash',
    'lookup',
    'status',
    'payload',
    'provider_reference',
    'source_url',
    'fetched_at',
    'expires_at',
    'stale_until',
    'retry_after',
    'failure_count',
    'last_error_code',
])]
class OnlineContentCache extends Model
{
    protected $table = 'online_content_cache';

    protected function casts(): array
    {
        return [
            'resource_type' => OnlineContentType::class,
            'lookup' => 'array',
            'status' => OnlineContentStatus::class,
            'payload' => 'array',
            'fetched_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'stale_until' => 'immutable_datetime',
            'retry_after' => 'immutable_datetime',
            'failure_count' => 'integer',
        ];
    }
}
