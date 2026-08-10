<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'audio_analysis_profile_id',
    'feedback_count',
    'relevant_count',
    'irrelevant_count',
    'adjustments',
    'feature_statistics',
    'trained_at',
])]
class AudioSimilarityPersonalization extends Model
{
    /** @return BelongsTo<AudioAnalysisProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AudioAnalysisProfile::class, 'audio_analysis_profile_id');
    }

    protected function casts(): array
    {
        return [
            'feedback_count' => 'integer',
            'relevant_count' => 'integer',
            'irrelevant_count' => 'integer',
            'adjustments' => 'array',
            'feature_statistics' => 'array',
            'trained_at' => 'immutable_datetime',
        ];
    }
}
