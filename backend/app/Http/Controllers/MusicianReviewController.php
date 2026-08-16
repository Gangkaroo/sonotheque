<?php

namespace App\Http\Controllers;

use App\Enums\MusicianReviewDecision;
use App\Enums\OnlineContentStatus;
use App\Models\Album;
use App\Models\AlbumMusicianEnrichment;
use App\Models\AlbumMusicianReview;
use App\Music\Enrichment\AlbumMusicianCreditManager;
use App\Support\LibraryRootScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MusicianReviewController extends Controller
{
    public function __construct(
        private readonly LibraryRootScope $libraryRootScope,
        private readonly AlbumMusicianCreditManager $credits,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:ambiguous,failed,reviewed'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $validated['status'] ?? 'ambiguous';
        $base = $this->reviewableQuery($libraryRootId);
        $withoutDecision = fn (Builder $query): Builder => $query->whereDoesntHave(
            'album.musicianReviews',
            fn (Builder $reviews) => $reviews->where(
                'lookup_version',
                AlbumMusicianCreditManager::LOOKUP_VERSION,
            ),
        );
        $withDecision = fn (Builder $query): Builder => $query->whereHas(
            'album.musicianReviews',
            fn (Builder $reviews) => $reviews->where(
                'lookup_version',
                AlbumMusicianCreditManager::LOOKUP_VERSION,
            ),
        );
        $counts = [
            'ambiguous' => $withoutDecision((clone $base)->where('status', OnlineContentStatus::Ambiguous->value))->count(),
            'failed' => $withoutDecision((clone $base)->where('status', OnlineContentStatus::Error->value))->count(),
            'reviewed' => $withDecision(clone $base)->count(),
        ];
        $query = match ($status) {
            'failed' => $withoutDecision($base->where('status', OnlineContentStatus::Error->value)),
            'reviewed' => $withDecision($base),
            default => $withoutDecision($base->where('status', OnlineContentStatus::Ambiguous->value)),
        };
        $paginator = $query
            ->with([
                'album' => fn ($albums) => $albums
                    ->withCount('tracks')
                    ->with([
                        'primaryArtist:id,name',
                        'artwork:id',
                        'libraryRoot:id,name',
                        'musicianReviews' => fn ($reviews) => $reviews->where(
                            'lookup_version',
                            AlbumMusicianCreditManager::LOOKUP_VERSION,
                        ),
                    ]),
            ])
            ->orderByDesc('fetched_at')
            ->orderBy('album_id')
            ->paginate(20);

        return response()->json([
            'items' => $paginator->getCollection()
                ->map(fn (AlbumMusicianEnrichment $enrichment): array => $this->item($enrichment))
                ->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'lastPage' => max(1, $paginator->lastPage()),
            'counts' => $counts,
        ]);
    }

    public function retry(Request $request, Album $album): JsonResponse
    {
        $album = $this->scopedAlbum($request, $album);
        $enrichment = $this->currentReviewableEnrichment($album);
        if ($enrichment->status !== OnlineContentStatus::Error) {
            throw ValidationException::withMessages([
                'album' => 'Only failed musician lookups can be retried from this review page.',
            ]);
        }

        return response()->json($this->credits->retry($album), 202);
    }

    public function decide(Request $request, Album $album): JsonResponse
    {
        $album = $this->scopedAlbum($request, $album);
        $this->currentReviewableEnrichment($album);
        $validated = $request->validate([
            'decision' => ['required', Rule::enum(MusicianReviewDecision::class)],
        ]);
        $review = AlbumMusicianReview::query()->updateOrCreate([
            'album_id' => $album->id,
            'lookup_version' => AlbumMusicianCreditManager::LOOKUP_VERSION,
        ], [
            'decision' => $validated['decision'],
            'reviewed_at' => now(),
        ]);

        return response()->json($this->reviewPayload($review));
    }

    public function reopen(Request $request, Album $album): JsonResponse
    {
        $album = $this->scopedAlbum($request, $album);
        AlbumMusicianReview::query()
            ->where('album_id', $album->id)
            ->where('lookup_version', AlbumMusicianCreditManager::LOOKUP_VERSION)
            ->delete();

        return response()->json(['reviewed' => false]);
    }

    /** @return Builder<AlbumMusicianEnrichment> */
    private function reviewableQuery(?int $libraryRootId): Builder
    {
        return AlbumMusicianEnrichment::query()
            ->where('lookup_version', AlbumMusicianCreditManager::LOOKUP_VERSION)
            ->whereIn('status', [
                OnlineContentStatus::Ambiguous->value,
                OnlineContentStatus::Error->value,
            ])
            ->whereHas('album', fn (Builder $albums) => $this->libraryRootScope
                ->albums($albums, $libraryRootId)
                ->has('tracks'));
    }

    private function scopedAlbum(Request $request, Album $album): Album
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        abort_unless(
            $this->libraryRootScope
                ->albums(Album::query(), $libraryRootId)
                ->whereKey($album->id)
                ->exists(),
            404,
        );

        return $album;
    }

    private function currentReviewableEnrichment(Album $album): AlbumMusicianEnrichment
    {
        $enrichment = AlbumMusicianEnrichment::query()
            ->where('album_id', $album->id)
            ->where('lookup_version', AlbumMusicianCreditManager::LOOKUP_VERSION)
            ->whereIn('status', [
                OnlineContentStatus::Ambiguous->value,
                OnlineContentStatus::Error->value,
            ])
            ->first();
        if ($enrichment === null) {
            throw ValidationException::withMessages([
                'album' => 'This album no longer has a musician lookup that requires review.',
            ]);
        }

        return $enrichment;
    }

    /** @return array<string, mixed> */
    private function item(AlbumMusicianEnrichment $enrichment): array
    {
        $album = $enrichment->album;
        $review = $album->musicianReviews->first();

        return [
            'album' => [
                'id' => $album->id,
                'title' => $album->title,
                'originalReleaseYear' => $album->original_release_year,
                'trackCount' => $album->tracks_count,
                'artworkThumbnailUrl' => $album->artwork_id === null
                    ? null
                    : "/api/artwork/{$album->artwork_id}/thumbnail",
                'primaryArtist' => $album->primaryArtist === null ? null : [
                    'id' => $album->primaryArtist->id,
                    'name' => $album->primaryArtist->name,
                ],
                'libraryRoot' => [
                    'id' => $album->libraryRoot->id,
                    'name' => $album->libraryRoot->name,
                ],
            ],
            'status' => $enrichment->status->value,
            'lookupVersion' => $enrichment->lookup_version,
            'candidateReleases' => $enrichment->candidate_releases ?? [],
            'errorCode' => $enrichment->last_error_code,
            'failureCount' => $enrichment->failure_count,
            'retryAfter' => $enrichment->retry_after?->toIso8601String(),
            'fetchedAt' => $enrichment->fetched_at?->toIso8601String(),
            'review' => $review === null ? null : $this->reviewPayload($review),
        ];
    }

    /** @return array<string, mixed> */
    private function reviewPayload(AlbumMusicianReview $review): array
    {
        return [
            'decision' => $review->decision->value,
            'reviewedAt' => $review->reviewed_at?->toIso8601String(),
        ];
    }
}
