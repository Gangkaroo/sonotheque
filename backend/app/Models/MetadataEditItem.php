<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'metadata_edit_job_id',
    'track_id',
    'media_file_id',
    'status',
    'fingerprint',
    'requested_changes',
    'preview',
    'error',
    'started_at',
    'finished_at',
])]
class MetadataEditItem extends Model
{
    /** @return BelongsTo<MetadataEditJob, $this> */
    public function editJob(): BelongsTo
    {
        return $this->belongsTo(MetadataEditJob::class, 'metadata_edit_job_id');
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    protected function casts(): array
    {
        return [
            'requested_changes' => 'array',
            'preview' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
