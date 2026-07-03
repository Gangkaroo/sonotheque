<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'metadata_edit_job_id',
    'metadata_edit_item_id',
    'media_file_id',
    'library_root_id',
    'source_relative_path',
    'backup_root',
    'backup_relative_path',
    'checksum',
    'file_size',
    'expires_at',
    'restored_at',
    'deleted_at',
])]
class MetadataBackup extends Model
{
    /** @return BelongsTo<MetadataEditJob, $this> */
    public function editJob(): BelongsTo
    {
        return $this->belongsTo(MetadataEditJob::class, 'metadata_edit_job_id');
    }

    /** @return BelongsTo<MetadataEditItem, $this> */
    public function editItem(): BelongsTo
    {
        return $this->belongsTo(MetadataEditItem::class, 'metadata_edit_item_id');
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'expires_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
