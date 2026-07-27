<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiPlatform\State\CreateLibraryRootProcessor;
use App\ApiPlatform\State\UpdateLibraryRootProcessor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'library_id',
    'name',
    'path',
    'path_hash',
    'cover_image_paths',
    'excluded_directories',
    'enabled',
    'include_patterns',
    'exclude_patterns',
    'last_scanned_at',
    'watch_enabled',
    'watch_poll_interval_minutes',
    'watch_reconcile_interval_minutes',
    'watch_status',
    'watch_checked_at',
    'watch_last_event_at',
    'watch_last_scan_at',
    'watch_last_path',
    'watch_error',
])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(order: ['name' => 'ASC']),
        new Post(
            rules: [
                'name' => ['required', 'string', 'max:255'],
                'path' => ['required', 'string', 'max:4096'],
                'cover_image_paths' => ['sometimes', 'array', 'min:1', 'max:20'],
                'cover_image_paths.*' => ['string', 'max:1024'],
                'excluded_directories' => ['sometimes', 'array', 'max:100'],
                'excluded_directories.*' => ['string', 'max:4096'],
                'watch_enabled' => ['sometimes', 'boolean'],
                'watch_poll_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
                'watch_reconcile_interval_minutes' => ['sometimes', 'integer', 'min:60', 'max:10080'],
            ],
            processor: CreateLibraryRootProcessor::class,
        ),
        new Patch(
            rules: [
                'name' => ['required', 'string', 'max:255'],
                'cover_image_paths' => ['sometimes', 'array', 'min:1', 'max:20'],
                'cover_image_paths.*' => ['string', 'max:1024'],
                'excluded_directories' => ['sometimes', 'array', 'max:100'],
                'excluded_directories.*' => ['string', 'max:4096'],
                'watch_enabled' => ['sometimes', 'boolean'],
                'watch_poll_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
                'watch_reconcile_interval_minutes' => ['sometimes', 'integer', 'min:60', 'max:10080'],
            ],
            processor: UpdateLibraryRootProcessor::class,
        ),
        new Delete(),
    ],
    paginationItemsPerPage: 100,
)]
class LibraryRoot extends Model
{
    /** @var list<string> */
    protected $hidden = [
        'path_hash',
        'library',
        'scanRuns',
        'albums',
        'mediaFiles',
        'watchDirectories',
    ];

    /** @return BelongsTo<Library, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** @return HasMany<ScanRun, $this> */
    public function scanRuns(): HasMany
    {
        return $this->hasMany(ScanRun::class);
    }

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /** @return HasMany<MediaFile, $this> */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    /** @return HasMany<LibraryWatchDirectory, $this> */
    public function watchDirectories(): HasMany
    {
        return $this->hasMany(LibraryWatchDirectory::class);
    }

    /** @return HasMany<LibraryActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(LibraryActivityLog::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'watch_enabled' => 'boolean',
            'watch_poll_interval_minutes' => 'integer',
            'watch_reconcile_interval_minutes' => 'integer',
            'include_patterns' => 'array',
            'exclude_patterns' => 'array',
            'cover_image_paths' => 'array',
            'excluded_directories' => 'array',
            'last_scanned_at' => 'immutable_datetime',
            'watch_checked_at' => 'immutable_datetime',
            'watch_last_event_at' => 'immutable_datetime',
            'watch_last_scan_at' => 'immutable_datetime',
        ];
    }
}
