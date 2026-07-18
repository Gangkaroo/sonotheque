<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'audio_analysis_profile_id',
    'content_fingerprint',
    'content_fingerprint_version',
    'features',
    'embedding',
    'runtime_ms',
    'windows_analyzed',
    'timings',
    'hardware',
])]
class AudioAnalysisArtifact extends Model
{
    /** @return BelongsTo<AudioAnalysisProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AudioAnalysisProfile::class, 'audio_analysis_profile_id');
    }

    /** @return HasMany<AudioAnalysisRunItem, $this> */
    public function runItems(): HasMany
    {
        return $this->hasMany(AudioAnalysisRunItem::class);
    }

    protected function casts(): array
    {
        return [
            'content_fingerprint_version' => 'integer',
            'features' => 'array',
            'embedding' => 'array',
            'runtime_ms' => 'integer',
            'windows_analyzed' => 'integer',
            'timings' => 'array',
            'hardware' => 'array',
        ];
    }
}
