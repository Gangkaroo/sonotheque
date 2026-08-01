<?php

namespace App\Music\Enrichment;

use App\Enums\OnlineContentStatus;
use App\Jobs\RefreshAlbumMusicianCredits;
use App\Models\Album;
use App\Models\AlbumDiscogsMusicianSource;
use App\Models\AlbumMusicianCredit;
use App\Models\AlbumMusicianEnrichment;
use App\Models\AlbumMusicianCreditSuppression;
use App\Models\ApplicationSetting;
use App\Models\ManualAlbumMusicianCredit;
use App\Models\Musician;
use App\Models\Track;
use App\Music\Enrichment\Data\MusicianCreditCollection;
use App\Music\Enrichment\Providers\MusicBrainzMusicianCreditProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AlbumMusicianCreditManager
{
    public const LOOKUP_VERSION = 4;

    private const PROVIDER = 'musicbrainz';

    public function __construct(
        private readonly MusicBrainzMusicianCreditProvider $provider,
        private readonly DiscogsMusicianCreditImporter $discogs,
    ) {
    }

    /** @return array<string, mixed> */
    public function forAlbum(Album $album): array
    {
        $hasManualCredits = ManualAlbumMusicianCredit::query()
            ->where('album_id', $album->id)
            ->exists();
        if (! ApplicationSetting::current()->online_information_enabled) {
            return $this->state(
                OnlineContentStatus::Ready->value,
                $this->payload($album, null, 'disabled'),
            );
        }

        $enrichment = AlbumMusicianEnrichment::query()->find($album->id);
        if ($this->needsRefresh($enrichment)) {
            $enrichment = $this->queueRefresh($album, $enrichment);
        } elseif (
            $enrichment?->status === OnlineContentStatus::Pending
            && $enrichment->updated_at?->lt(now()->subMinutes(5))
        ) {
            $enrichment->touch();
            RefreshAlbumMusicianCredits::dispatch($album->id, self::LOOKUP_VERSION);
        }

        if ($enrichment === null) {
            return $this->state(OnlineContentStatus::Pending->value);
        }

        $displayStatus = $hasManualCredits
            ? OnlineContentStatus::Ready
            : $enrichment->status;

        return $this->state(
            $displayStatus->value,
            $enrichment->status === OnlineContentStatus::Ready || $hasManualCredits
                ? $this->payload($album, $enrichment, $enrichment->status->value)
                : $this->resolutionPayload($enrichment),
            $enrichment->last_error_code,
        );
    }

    /** @return array<string, mixed> */
    public function editorForAlbum(Album $album): array
    {
        $suppressedKeys = $this->suppressedKeys($album);
        $imported = $this->importedCredits($album)
            ->map(fn (AlbumMusicianCredit $credit): array => [
                'id' => null,
                'sourceKey' => $this->sourceKey($credit),
                'provider' => $credit->provider,
                'manual' => false,
                'hidden' => $suppressedKeys->contains($this->sourceKey($credit)),
                'musician' => $this->musicianSummary($credit->musician),
                'role' => $credit->role,
                'creditedAs' => $credit->credited_as,
                'guest' => $credit->is_guest,
                'additional' => $credit->is_additional,
                'trackIds' => $credit->track_id === null ? [] : [$credit->track_id],
                'tracks' => $credit->track === null ? [] : [$this->trackSummary($credit->track)],
            ]);
        $manual = $this->manualCredits($album)
            ->map(fn (ManualAlbumMusicianCredit $credit): array => [
                'id' => $credit->id,
                'sourceKey' => null,
                'provider' => 'local',
                'manual' => true,
                'hidden' => false,
                'musician' => $this->musicianSummary($credit->musician),
                'role' => $credit->role,
                'creditedAs' => $credit->credited_as,
                'guest' => $credit->is_guest,
                'additional' => $credit->is_additional,
                'trackIds' => $credit->tracks->pluck('id')->all(),
                'tracks' => $credit->tracks->map(fn (Track $track): array => $this->trackSummary($track))->all(),
            ]);

        return [
            'discogs' => $this->discogsSourcePayload($album),
            'tracks' => Track::query()
                ->where('album_id', $album->id)
                ->orderByRaw('COALESCE(disc_number, 0), COALESCE(track_number, 0), id')
                ->get(['id', 'title', 'disc_number', 'track_number'])
                ->map(fn (Track $track): array => $this->trackSummary($track))
                ->all(),
            'items' => $imported
                ->concat($manual)
                ->sortBy(fn (array $item): string => implode('|', [
                    mb_strtolower($item['musician']['name']),
                    $item['manual'] ? '1' : '0',
                    mb_strtolower($item['role']),
                ]))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function discogsSourcePayload(Album $album): array
    {
        $source = $album->discogsMusicianSource()->first();
        $ownedOptions = $album->ownedCopies()
            ->where('provider', 'discogs')
            ->whereNotNull('external_release_id')
            ->get(['id', 'physical_format', 'external_release_id'])
            ->map(fn ($copy): array => [
                'key' => 'owned-copy:'.$copy->id,
                'sourceType' => AlbumDiscogsMusicianSource::SOURCE_OWNED_COPY,
                'ownedCopyId' => $copy->id,
                'format' => $copy->physical_format,
                'releaseId' => $copy->external_release_id,
            ]);
        $musicBrainzOptions = collect(
            AlbumMusicianEnrichment::query()->find($album->id)?->related_discogs_release_ids ?? [],
        )
            ->filter(fn (mixed $releaseId): bool => is_numeric($releaseId) && (int) $releaseId > 0)
            ->map(fn (mixed $releaseId): array => [
                'key' => 'musicbrainz:'.(int) $releaseId,
                'sourceType' => AlbumDiscogsMusicianSource::SOURCE_MUSICBRAINZ,
                'ownedCopyId' => null,
                'format' => null,
                'releaseId' => (int) $releaseId,
            ]);
        $options = $ownedOptions
            ->concat($musicBrainzOptions)
            ->unique(fn (array $option): string => $option['key'])
            ->values()
            ->all();

        return [
            'selectedKey' => $source === null
                ? null
                : ($source->source_type === AlbumDiscogsMusicianSource::SOURCE_OWNED_COPY
                    ? 'owned-copy:'.$source->owned_album_copy_id
                    : 'musicbrainz:'.$source->release_id),
            'selectedSourceType' => $source?->source_type,
            'selectedOwnedCopyId' => $source?->owned_album_copy_id,
            'selectedReleaseId' => $source?->release_id,
            'sourceUrl' => $source?->source_url,
            'fetchedAt' => $source?->fetched_at?->toIso8601String(),
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function createManualCredit(Album $album, array $values): array
    {
        DB::transaction(function () use ($album, $values): void {
            $credit = ManualAlbumMusicianCredit::query()->create([
                'album_id' => $album->id,
                ...$this->manualCreditValues($values),
            ]);
            $credit->tracks()->sync($this->validatedTrackIds($album, $values['trackIds'] ?? []));
        });

        return $this->editorForAlbum($album);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function updateManualCredit(
        Album $album,
        ManualAlbumMusicianCredit $credit,
        array $values,
    ): array {
        abort_unless($credit->album_id === $album->id, 404);
        DB::transaction(function () use ($album, $credit, $values): void {
            $credit->update($this->manualCreditValues($values));
            $credit->tracks()->sync($this->validatedTrackIds($album, $values['trackIds'] ?? []));
        });

        return $this->editorForAlbum($album);
    }

    /** @return array<string, mixed> */
    public function deleteManualCredit(Album $album, ManualAlbumMusicianCredit $credit): array
    {
        abort_unless($credit->album_id === $album->id, 404);
        $credit->delete();

        return $this->editorForAlbum($album);
    }

    /** @return array<string, mixed> */
    public function suppressImportedCredit(Album $album, string $sourceKey): array
    {
        $credit = $this->importedCredits($album)
            ->first(fn (AlbumMusicianCredit $candidate): bool => $this->sourceKey($candidate) === $sourceKey);
        if ($credit === null) {
            throw ValidationException::withMessages([
                'sourceKey' => 'The imported musician credit no longer exists for this album.',
            ]);
        }

        AlbumMusicianCreditSuppression::query()->firstOrCreate([
            'album_id' => $album->id,
            'provider' => $credit->provider,
            'source_credit_key' => $sourceKey,
        ]);

        return $this->editorForAlbum($album);
    }

    /** @return array<string, mixed> */
    public function restoreImportedCredit(Album $album, string $sourceKey): array
    {
        AlbumMusicianCreditSuppression::query()
            ->where('album_id', $album->id)
            ->where('source_credit_key', $sourceKey)
            ->delete();

        return $this->editorForAlbum($album);
    }

    /** @return array<string, mixed> */
    public function resolveRelease(Album $album, string $releaseId): array
    {
        $enrichment = AlbumMusicianEnrichment::query()->find($album->id);
        $candidateIds = collect($enrichment?->candidate_releases ?? [])
            ->pluck('id')
            ->filter(fn (mixed $candidateId): bool => is_string($candidateId));

        if ($enrichment === null || ! $candidateIds->containsStrict($releaseId)) {
            throw ValidationException::withMessages([
                'releaseId' => 'Select one of the MusicBrainz releases suggested for this album.',
            ]);
        }

        $enrichment->update([
            'provider' => self::PROVIDER,
            'lookup_version' => self::LOOKUP_VERSION,
            'status' => OnlineContentStatus::Pending,
            'selected_release_id' => $releaseId,
            'provider_release_id' => null,
            'source_url' => null,
            'fetched_at' => null,
            'expires_at' => null,
            'retry_after' => null,
            'failure_count' => 0,
            'last_error_code' => null,
        ]);
        RefreshAlbumMusicianCredits::dispatch($album->id, self::LOOKUP_VERSION);

        return $this->state(
            OnlineContentStatus::Pending->value,
            $this->resolutionPayload($enrichment->fresh()),
        );
    }

    public function refresh(int $albumId): void
    {
        $album = Album::query()->find($albumId);
        if ($album === null || ! ApplicationSetting::current()->online_information_enabled) {
            return;
        }

        $enrichment = AlbumMusicianEnrichment::query()->updateOrCreate(
            ['album_id' => $album->id],
            [
                'provider' => self::PROVIDER,
                'lookup_version' => self::LOOKUP_VERSION,
                'status' => OnlineContentStatus::Pending,
            ],
        );

        try {
            $result = $this->provider->fetch($album, $enrichment->selected_release_id);
            if ($result === null) {
                $this->completeWithoutCredits($album, $enrichment, OnlineContentStatus::NotFound);

                return;
            }

            $this->store($album, $enrichment, $result);
        } catch (AmbiguousMusicBrainzReleaseException $exception) {
            $this->completeAmbiguous($album, $enrichment, $exception->candidates);
        } catch (AmbiguousEnrichmentMatchException) {
            $this->completeAmbiguous($album, $enrichment, []);
        } catch (EnrichmentProviderException $exception) {
            $this->markFailure($enrichment, $exception->errorCode, $exception->retryAfterSeconds);
        } catch (Throwable $exception) {
            $this->markFailure($enrichment, 'provider_error');

            throw $exception;
        }
    }

    private function needsRefresh(?AlbumMusicianEnrichment $enrichment): bool
    {
        if ($enrichment === null || $enrichment->lookup_version !== self::LOOKUP_VERSION) {
            return true;
        }

        if ($enrichment->status === OnlineContentStatus::Pending) {
            return false;
        }

        if ($enrichment->status === OnlineContentStatus::Error) {
            return ! ($enrichment->retry_after?->isFuture() ?? false);
        }

        return $enrichment->expires_at?->isPast() ?? true;
    }

    private function queueRefresh(
        Album $album,
        ?AlbumMusicianEnrichment $enrichment,
    ): AlbumMusicianEnrichment {
        $enrichment ??= new AlbumMusicianEnrichment(['album_id' => $album->id]);
        $enrichment->fill([
            'provider' => self::PROVIDER,
            'lookup_version' => self::LOOKUP_VERSION,
            'status' => OnlineContentStatus::Pending,
            'retry_after' => null,
            'last_error_code' => null,
        ])->save();

        RefreshAlbumMusicianCredits::dispatch($album->id, self::LOOKUP_VERSION);

        return $enrichment;
    }

    private function store(
        Album $album,
        AlbumMusicianEnrichment $enrichment,
        MusicianCreditCollection $result,
    ): void {
        DB::transaction(function () use ($album, $enrichment, $result): void {
            AlbumMusicianCredit::query()
                ->where('album_id', $album->id)
                ->where('provider', self::PROVIDER)
                ->delete();

            foreach ($result->credits as $credit) {
                $musician = Musician::query()->updateOrCreate([
                    'provider' => self::PROVIDER,
                    'provider_reference' => $credit->providerReference,
                ], [
                    'name' => $credit->name,
                    'sort_name' => $credit->sortName,
                    'disambiguation' => $credit->disambiguation,
                    'entity_type' => $credit->entityType,
                ]);
                AlbumMusicianCredit::query()->create([
                    'album_id' => $album->id,
                    'track_id' => $credit->trackId,
                    'musician_id' => $musician->id,
                    'provider' => self::PROVIDER,
                    'source_entity_type' => $credit->sourceEntityType,
                    'source_entity_reference' => $credit->sourceEntityReference,
                    'relationship_type' => $credit->relationshipType,
                    'role' => $credit->role,
                    'credited_as' => $credit->creditedAs,
                    'attributes' => $credit->attributes,
                    'is_guest' => $credit->guest,
                    'is_additional' => $credit->additional,
                ]);
            }

            $expiresAt = now()->addDays(max(1, (int) config('sonotheque.enrichment.ready_cache_days', 30)));
            $enrichment->update([
                'provider' => self::PROVIDER,
                'lookup_version' => self::LOOKUP_VERSION,
                'status' => OnlineContentStatus::Ready,
                'provider_release_id' => $result->releaseId,
                'source_url' => $result->sourceUrl,
                'related_discogs_release_ids' => $result->discogsReleaseIds,
                'fetched_at' => now(),
                'expires_at' => $expiresAt,
                'retry_after' => null,
                'failure_count' => 0,
                'last_error_code' => null,
            ]);
        });

        $this->discogs->syncMusicBrainzSourceIfUnselected(
            $album,
            $result->discogsReleaseIds,
        );
    }

    private function completeWithoutCredits(
        Album $album,
        AlbumMusicianEnrichment $enrichment,
        OnlineContentStatus $status,
    ): void {
        DB::transaction(function () use ($album, $enrichment, $status): void {
            AlbumMusicianCredit::query()
                ->where('album_id', $album->id)
                ->where('provider', self::PROVIDER)
                ->delete();
            $enrichment->update([
                'provider' => self::PROVIDER,
                'lookup_version' => self::LOOKUP_VERSION,
                'status' => $status,
                'provider_release_id' => null,
                'source_url' => null,
                'related_discogs_release_ids' => null,
                'fetched_at' => now(),
                'expires_at' => now()->addHours(
                    max(1, (int) config('sonotheque.enrichment.not_found_cache_hours', 24)),
                ),
                'retry_after' => null,
                'failure_count' => 0,
                'last_error_code' => null,
            ]);
        });
    }

    /** @param  list<array<string, mixed>>  $candidates */
    private function completeAmbiguous(
        Album $album,
        AlbumMusicianEnrichment $enrichment,
        array $candidates,
    ): void {
        DB::transaction(function () use ($album, $enrichment, $candidates): void {
            AlbumMusicianCredit::query()
                ->where('album_id', $album->id)
                ->where('provider', self::PROVIDER)
                ->delete();
            $enrichment->update([
                'provider' => self::PROVIDER,
                'lookup_version' => self::LOOKUP_VERSION,
                'status' => OnlineContentStatus::Ambiguous,
                'provider_release_id' => null,
                'selected_release_id' => null,
                'candidate_releases' => $candidates,
                'source_url' => null,
                'related_discogs_release_ids' => null,
                'fetched_at' => now(),
                'expires_at' => now()->addHours(
                    max(1, (int) config('sonotheque.enrichment.not_found_cache_hours', 24)),
                ),
                'retry_after' => null,
                'failure_count' => 0,
                'last_error_code' => null,
            ]);
        });
    }

    private function markFailure(
        AlbumMusicianEnrichment $enrichment,
        string $errorCode,
        ?int $providerRetryAfterSeconds = null,
    ): void {
        $failureCount = max(1, $enrichment->failure_count + 1);
        $baseMinutes = max(1, (int) config('sonotheque.enrichment.error_retry_minutes', 15));
        $maximumMinutes = max($baseMinutes, (int) config('sonotheque.enrichment.max_error_retry_minutes', 360));
        $backoffMinutes = min($maximumMinutes, $baseMinutes * (2 ** min(5, $failureCount - 1)));
        $providerMinutes = $providerRetryAfterSeconds === null
            ? 0
            : (int) ceil($providerRetryAfterSeconds / 60);
        $retryAfter = now()->addMinutes(max($backoffMinutes, $providerMinutes));

        $enrichment->update([
            'status' => OnlineContentStatus::Error,
            'fetched_at' => now(),
            'expires_at' => $retryAfter,
            'retry_after' => $retryAfter,
            'failure_count' => $failureCount,
            'last_error_code' => $errorCode,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(
        Album $album,
        ?AlbumMusicianEnrichment $enrichment,
        string $providerStatus,
    ): array {
        $suppressedKeys = $this->suppressedKeys($album);
        $importedCreditGroups = $this->importedCredits($album)
            ->reject(fn (AlbumMusicianCredit $credit): bool => $suppressedKeys->contains($this->sourceKey($credit)))
            ->groupBy(fn (AlbumMusicianCredit $credit): string => implode('|', [
                $credit->musician_id,
                $credit->provider,
                $credit->source_entity_type,
                $credit->relationship_type,
                $credit->role,
                $credit->credited_as ?? '',
                $credit->is_guest ? 'guest' : '',
                $credit->is_additional ? 'additional' : '',
            ]))
            ->map(function (Collection $group): array {
                /** @var AlbumMusicianCredit $credit */
                $credit = $group->firstOrFail();

                return [
                    'musician' => $credit->musician,
                    'credit' => [
                        'id' => null,
                        'sourceKeys' => $group
                            ->map(fn (AlbumMusicianCredit $item): string => $this->sourceKey($item))
                            ->values()
                            ->all(),
                        'provider' => $credit->provider,
                        'manual' => false,
                        'relationshipType' => $credit->relationship_type,
                        'role' => $credit->role,
                        'creditedAs' => $credit->credited_as,
                        'guest' => $credit->is_guest,
                        'additional' => $credit->is_additional,
                        'scope' => $credit->source_entity_type,
                        'tracks' => $group
                            ->pluck('track')
                            ->filter()
                            ->unique('id')
                            ->sortBy(fn (Track $track): string => sprintf(
                                '%05d:%05d',
                                $track->disc_number ?? 0,
                                $track->track_number ?? 0,
                            ))
                            ->values()
                            ->map(fn (Track $track): array => $this->trackSummary($track))
                            ->all(),
                    ],
                ];
            });
        $manualCreditGroups = $this->manualCredits($album)
            ->map(fn (ManualAlbumMusicianCredit $credit): array => [
                'musician' => $credit->musician,
                'credit' => [
                    'id' => $credit->id,
                    'sourceKeys' => [],
                    'provider' => 'local',
                    'manual' => true,
                    'relationshipType' => 'manual',
                    'role' => $credit->role,
                    'creditedAs' => $credit->credited_as,
                    'guest' => $credit->is_guest,
                    'additional' => $credit->is_additional,
                    'scope' => $credit->tracks->isEmpty() ? 'release' : 'recording',
                    'tracks' => $credit->tracks
                        ->map(fn (Track $track): array => $this->trackSummary($track))
                        ->all(),
                ],
            ]);

        return [
            'releaseId' => $enrichment?->provider_release_id,
            'selectedReleaseId' => $enrichment?->selected_release_id,
            'candidateReleases' => $enrichment?->candidate_releases ?? [],
            'sourceUrl' => $enrichment?->source_url,
            'fetchedAt' => $enrichment?->fetched_at?->toIso8601String(),
            'providerStatus' => $providerStatus,
            'musicians' => $importedCreditGroups
                ->concat($manualCreditGroups)
                ->groupBy(fn (array $item): int => $item['musician']->id)
                ->map(function (Collection $items): array {
                    /** @var Musician $musician */
                    $musician = $items->firstOrFail()['musician'];

                    return [
                        ...$this->musicianSummary($musician),
                        'credits' => $items->pluck('credit')->values()->all(),
                    ];
                })
                ->sortBy(fn (array $musician): string => mb_strtolower($musician['sortName'] ?? $musician['name']))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function resolutionPayload(?AlbumMusicianEnrichment $enrichment): ?array
    {
        if ($enrichment === null || ($enrichment->candidate_releases ?? []) === []) {
            return null;
        }

        return [
            'releaseId' => $enrichment->provider_release_id,
            'selectedReleaseId' => $enrichment->selected_release_id,
            'candidateReleases' => $enrichment->candidate_releases,
            'sourceUrl' => $enrichment->source_url,
            'fetchedAt' => $enrichment->fetched_at?->toIso8601String(),
            'musicians' => [],
        ];
    }

    /** @return Collection<int, AlbumMusicianCredit> */
    private function importedCredits(Album $album): Collection
    {
        return AlbumMusicianCredit::query()
            ->where('album_id', $album->id)
            ->with([
                'musician:id,provider,provider_reference,name,sort_name,disambiguation,entity_type',
                'track:id,title,disc_number,track_number',
            ])
            ->get();
    }

    /** @return Collection<int, ManualAlbumMusicianCredit> */
    private function manualCredits(Album $album): Collection
    {
        return ManualAlbumMusicianCredit::query()
            ->where('album_id', $album->id)
            ->with([
                'musician:id,provider,provider_reference,name,sort_name,disambiguation,entity_type',
                'tracks:id,title,disc_number,track_number',
            ])
            ->get();
    }

    /** @return Collection<int, string> */
    private function suppressedKeys(Album $album): Collection
    {
        return AlbumMusicianCreditSuppression::query()
            ->where('album_id', $album->id)
            ->pluck('source_credit_key');
    }

    private function sourceKey(AlbumMusicianCredit $credit): string
    {
        return hash('sha256', implode('|', [
            $credit->provider,
            $credit->musician->provider,
            $credit->musician->provider_reference,
            $credit->source_entity_type,
            $credit->source_entity_reference,
            $credit->relationship_type,
            $credit->role,
            $credit->credited_as ?? '',
            $credit->is_guest ? 'guest' : '',
            $credit->is_additional ? 'additional' : '',
        ]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function manualCreditValues(array $values): array
    {
        return [
            'musician_id' => $this->resolveMusician($values)->id,
            'role' => trim((string) $values['role']),
            'credited_as' => filled($values['creditedAs'] ?? null)
                ? trim((string) $values['creditedAs'])
                : null,
            'is_guest' => (bool) ($values['guest'] ?? false),
            'is_additional' => (bool) ($values['additional'] ?? false),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private function resolveMusician(array $values): Musician
    {
        if (is_numeric($values['musicianId'] ?? null)) {
            $musician = Musician::query()->find((int) $values['musicianId']);
            if ($musician !== null) {
                return $musician;
            }
        }

        $name = trim((string) ($values['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a musician name or select an existing musician.',
            ]);
        }

        $existing = Musician::query()
            ->where('provider', 'local')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        return $existing ?? Musician::query()->create([
            'provider' => 'local',
            'provider_reference' => (string) Str::uuid(),
            'name' => $name,
            'sort_name' => $name,
        ]);
    }

    /**
     * @param  mixed  $trackIds
     * @return list<int>
     */
    private function validatedTrackIds(Album $album, mixed $trackIds): array
    {
        $requested = collect(is_array($trackIds) ? $trackIds : [])
            ->filter(fn (mixed $trackId): bool => is_numeric($trackId))
            ->map(fn (mixed $trackId): int => (int) $trackId)
            ->unique()
            ->values();
        $valid = Track::query()
            ->where('album_id', $album->id)
            ->whereIn('id', $requested)
            ->pluck('id');
        if ($valid->count() !== $requested->count()) {
            throw ValidationException::withMessages([
                'trackIds' => 'Every selected track must belong to this album.',
            ]);
        }

        return $valid->all();
    }

    /** @return array<string, mixed> */
    private function musicianSummary(Musician $musician): array
    {
        return [
            'id' => $musician->id,
            'provider' => $musician->provider,
            'name' => $musician->name,
            'sortName' => $musician->sort_name,
            'disambiguation' => $musician->disambiguation,
            'entityType' => $musician->entity_type,
        ];
    }

    /** @return array<string, mixed> */
    private function trackSummary(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'discNumber' => $track->disc_number,
            'trackNumber' => $track->track_number,
        ];
    }

    /** @return array<string, mixed> */
    private function state(
        string $status,
        ?array $data = null,
        ?string $errorCode = null,
    ): array {
        return [
            'status' => $status,
            'provider' => self::PROVIDER,
            'lookupVersion' => self::LOOKUP_VERSION,
            'data' => $data,
            'errorCode' => $errorCode,
        ];
    }
}
