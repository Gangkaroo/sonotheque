<?php

namespace App\Models;

use ApiPlatform\Laravel\Eloquent\Filter\EqualsFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiPlatform\State\CancelScanRunProcessor;
use App\ApiPlatform\State\StartScanRunProcessor;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'library_root_id',
    'status',
    'trigger',
    'files_discovered',
    'files_processed',
    'files_added',
    'files_updated',
    'files_removed',
    'warning_count',
    'error_count',
    'started_at',
    'finished_at',
    'cancel_requested_at',
    'summary',
])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(
            order: ['created_at' => 'DESC'],
            parameters: [
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    castToNativeType: true,
                ),
                'libraryRoot' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1],
                    filter: EqualsFilter::class,
                    property: 'library_root_id',
                    castToNativeType: true,
                ),
            ],
            strictQueryParameterValidation: true,
        ),
        new Post(
            rules: ['library_root_id' => ['required', 'integer', 'min:1']],
            processor: StartScanRunProcessor::class,
        ),
        new Patch(
            uriTemplate: '/scan_runs/{id}/cancel',
            processor: CancelScanRunProcessor::class,
        ),
    ],
    paginationItemsPerPage: 50,
)]
class ScanRun extends Model
{
    /** @var list<string> */
    protected $hidden = ['libraryRoot'];

    /** @return BelongsTo<LibraryRoot, $this> */
    public function libraryRoot(): BelongsTo
    {
        return $this->belongsTo(LibraryRoot::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'trigger' => ScanTrigger::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }
}
