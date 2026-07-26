<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'status',
    'sample_size',
    'sample_track_ids',
    'results',
    'recommendation',
    'completed_configuration_count',
    'total_configuration_count',
    'error',
    'cancel_requested_at',
    'started_at',
    'finished_at',
])]
class AudioAnalyzerBenchmark extends Model
{
    protected function casts(): array
    {
        return [
            'sample_size' => 'integer',
            'sample_track_ids' => 'array',
            'results' => 'array',
            'recommendation' => 'array',
            'completed_configuration_count' => 'integer',
            'total_configuration_count' => 'integer',
            'cancel_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
