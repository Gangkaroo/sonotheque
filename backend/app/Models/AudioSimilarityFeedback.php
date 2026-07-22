<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'audio_analysis_profile_id',
    'source_track_id',
    'candidate_track_id',
    'configuration',
    'verdict',
])]
class AudioSimilarityFeedback extends Model
{
    /** @return BelongsTo<AudioAnalysisProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AudioAnalysisProfile::class, 'audio_analysis_profile_id');
    }

    /** @return BelongsTo<Track, $this> */
    public function sourceTrack(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'source_track_id');
    }

    /** @return BelongsTo<Track, $this> */
    public function candidateTrack(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'candidate_track_id');
    }
}
