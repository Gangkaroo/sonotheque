<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'audio_analysis_profile_id',
    'phase',
    'status',
    'selection_seed',
    'requested_track_count',
    'selected_track_count',
    'summary',
    'started_at',
    'finished_at',
    'cancel_requested_at',
    'heartbeat_at',
])]
class AudioAnalysisRun extends Model
{
    /** @return BelongsTo<AudioAnalysisProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AudioAnalysisProfile::class, 'audio_analysis_profile_id');
    }

    /** @return HasMany<AudioAnalysisRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AudioAnalysisRunItem::class);
    }

    protected function casts(): array
    {
        return [
            'requested_track_count' => 'integer',
            'selected_track_count' => 'integer',
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
        ];
    }
}
