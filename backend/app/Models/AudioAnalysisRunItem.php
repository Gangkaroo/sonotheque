<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'audio_analysis_run_id',
    'track_id',
    'library_root_id',
    'genre_id',
    'audio_analysis_artifact_id',
    'content_fingerprint',
    'content_fingerprint_version',
    'position',
    'status',
    'error',
])]
class AudioAnalysisRunItem extends Model
{
    /** @return BelongsTo<AudioAnalysisRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AudioAnalysisRun::class, 'audio_analysis_run_id');
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    /** @return BelongsTo<Genre, $this> */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /** @return BelongsTo<AudioAnalysisArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AudioAnalysisArtifact::class, 'audio_analysis_artifact_id');
    }

    protected function casts(): array
    {
        return [
            'content_fingerprint_version' => 'integer',
            'position' => 'integer',
        ];
    }
}
