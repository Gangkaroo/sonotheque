<?php

namespace App\Music\Enrichment;

use App\Models\Album;
use App\Models\AlbumDiscogsMusicianSource;
use App\Models\AlbumMusicianCredit;
use App\Models\ApplicationSetting;
use App\Models\Musician;
use App\Models\OwnedAlbumCopy;
use App\Models\Track;
use App\Music\Discogs\DiscogsApiClient;
use App\Music\Discogs\DiscogsApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscogsMusicianCreditImporter
{
    private const PROVIDER = 'discogs';

    public function __construct(private readonly DiscogsApiClient $discogs)
    {
    }

    /** @param array<string, mixed> $release */
    public function syncIfUnselected(Album $album, OwnedAlbumCopy $copy, array $release): void
    {
        $source = AlbumDiscogsMusicianSource::query()->find($album->id);
        if ($source === null || $source->source_type === AlbumDiscogsMusicianSource::SOURCE_MUSICBRAINZ) {
            $this->syncRelease($album, $copy, $release);
        }
    }

    /** @param  list<int>  $releaseIds */
    public function syncMusicBrainzSourceIfUnselected(Album $album, array $releaseIds): void
    {
        $releaseIds = collect($releaseIds)
            ->filter(fn (mixed $releaseId): bool => is_numeric($releaseId) && (int) $releaseId > 0)
            ->map(fn (mixed $releaseId): int => (int) $releaseId)
            ->unique()
            ->values();
        if ($releaseIds->count() !== 1
            || AlbumDiscogsMusicianSource::query()->where('album_id', $album->id)->exists()) {
            return;
        }

        $settings = ApplicationSetting::current();
        if (! $settings->hasDiscogsConnection()) {
            return;
        }

        $releaseId = $releaseIds->first();
        try {
            $release = $this->discogs->release(
                $settings->discogs_personal_access_token,
                $releaseId,
            );
        } catch (DiscogsApiException $exception) {
            Log::warning('Automatic Discogs musician-credit import failed.', [
                'album_id' => $album->id,
                'release_id' => $releaseId,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $this->syncRelease(
            $album,
            null,
            $release,
            AlbumDiscogsMusicianSource::SOURCE_MUSICBRAINZ,
        );
    }

    /** @param array<string, mixed> $release */
    public function syncIfSelected(Album $album, OwnedAlbumCopy $copy, array $release): void
    {
        if (AlbumDiscogsMusicianSource::query()
            ->where('album_id', $album->id)
            ->where('owned_album_copy_id', $copy->id)
            ->exists()) {
            $this->syncRelease($album, $copy, $release);
        }
    }

    public function select(Album $album, OwnedAlbumCopy $copy, bool $refresh = false): void
    {
        $this->ensureLinkedCopy($album, $copy);
        $settings = ApplicationSetting::current();
        if (! $settings->hasDiscogsConnection()) {
            throw ValidationException::withMessages([
                'discogs' => 'Connect a Discogs account before importing musician credits.',
            ]);
        }

        $release = $this->discogs->release(
            $settings->discogs_personal_access_token,
            (int) $copy->external_release_id,
            $refresh,
        );
        $this->syncRelease($album, $copy, $release);
    }

    public function selectMusicBrainzRelease(Album $album, int $releaseId, bool $refresh = false): void
    {
        $relatedReleaseIds = collect($album->musicianEnrichment?->related_discogs_release_ids ?? [])
            ->map(fn (mixed $candidate): int => (int) $candidate);
        if ($releaseId < 1 || ! $relatedReleaseIds->containsStrict($releaseId)) {
            throw ValidationException::withMessages([
                'releaseId' => 'Select a Discogs release linked by MusicBrainz for this album.',
            ]);
        }

        $settings = ApplicationSetting::current();
        if (! $settings->hasDiscogsConnection()) {
            throw ValidationException::withMessages([
                'discogs' => 'Connect a Discogs account before importing musician credits.',
            ]);
        }

        $release = $this->discogs->release(
            $settings->discogs_personal_access_token,
            $releaseId,
            $refresh,
        );
        $this->syncRelease(
            $album,
            null,
            $release,
            AlbumDiscogsMusicianSource::SOURCE_MUSICBRAINZ,
        );
    }

    public function clearIfSelected(Album $album, OwnedAlbumCopy $copy): void
    {
        if (AlbumDiscogsMusicianSource::query()
            ->where('album_id', $album->id)
            ->where('owned_album_copy_id', $copy->id)
            ->exists()) {
            $this->clear($album);
        }
    }

    public function clear(Album $album): void
    {
        DB::transaction(function () use ($album): void {
            AlbumMusicianCredit::query()
                ->where('album_id', $album->id)
                ->where('provider', self::PROVIDER)
                ->delete();
            AlbumDiscogsMusicianSource::query()->where('album_id', $album->id)->delete();
        });
    }

    /** @param array<string, mixed> $release */
    private function syncRelease(
        Album $album,
        ?OwnedAlbumCopy $copy,
        array $release,
        string $sourceType = AlbumDiscogsMusicianSource::SOURCE_OWNED_COPY,
    ): void {
        if ($copy !== null) {
            $this->ensureLinkedCopy($album, $copy);
        }
        $releaseId = (int) ($release['id'] ?? 0);
        if ($releaseId < 1 || ($copy !== null && $releaseId !== (int) $copy->external_release_id)) {
            throw ValidationException::withMessages([
                'discogs' => 'The Discogs release no longer matches this owned copy.',
            ]);
        }

        $tracks = Track::query()
            ->where('album_id', $album->id)
            ->get(['id', 'title', 'disc_number', 'track_number']);
        $credits = collect($release['musicianCredits'] ?? [])
            ->filter(fn (mixed $credit): bool => is_array($credit));

        DB::transaction(function () use (
            $album,
            $copy,
            $release,
            $releaseId,
            $sourceType,
            $tracks,
            $credits,
        ): void {
            AlbumMusicianCredit::query()
                ->where('album_id', $album->id)
                ->where('provider', self::PROVIDER)
                ->delete();

            foreach ($credits as $credit) {
                $name = $this->text($credit['name'] ?? null, 255);
                $providerReference = $this->text($credit['providerReference'] ?? null, 128);
                $role = $this->text($credit['role'] ?? null, 255);
                $sourceReference = $this->text($credit['sourceEntityReference'] ?? null, 128);
                if ($name === null || $providerReference === null || $role === null || $sourceReference === null) {
                    continue;
                }

                $trackId = $this->localTrackId(
                    $tracks,
                    $this->text($credit['trackPosition'] ?? null),
                    $this->text($credit['trackTitle'] ?? null),
                );
                $attributes = collect($credit['attributes'] ?? [])
                    ->filter(fn (mixed $attribute): bool => is_string($attribute))
                    ->values();
                if (($credit['trackPosition'] ?? null) !== null && $trackId === null) {
                    $attributes->push('unresolved-track');
                }
                $musician = Musician::query()->updateOrCreate([
                    'provider' => self::PROVIDER,
                    'provider_reference' => $providerReference,
                ], [
                    'name' => $name,
                    'sort_name' => $this->text($credit['sortName'] ?? null, 255),
                    'entity_type' => $this->text($credit['entityType'] ?? null, 64),
                ]);
                AlbumMusicianCredit::query()->create([
                    'album_id' => $album->id,
                    'track_id' => $trackId,
                    'musician_id' => $musician->id,
                    'provider' => self::PROVIDER,
                    'source_entity_type' => $trackId === null
                        ? 'release'
                        : (string) ($credit['sourceEntityType'] ?? 'recording'),
                    'source_entity_reference' => $sourceReference,
                    'relationship_type' => (string) ($credit['relationshipType'] ?? 'extraartist'),
                    'role' => $role,
                    'credited_as' => $this->text($credit['creditedAs'] ?? null, 255),
                    'attributes' => $attributes->unique()->all(),
                    'is_guest' => (bool) ($credit['guest'] ?? false),
                    'is_additional' => (bool) ($credit['additional'] ?? false),
                ]);
            }

            AlbumDiscogsMusicianSource::query()->updateOrCreate(
                ['album_id' => $album->id],
                [
                    'source_type' => $sourceType,
                    'owned_album_copy_id' => $copy?->id,
                    'release_id' => $releaseId,
                    'source_url' => $this->text($release['webUrl'] ?? null)
                        ?? rtrim((string) config('sonotheque.discogs.web_url'), '/').'/release/'.$releaseId,
                    'fetched_at' => now(),
                ],
            );
        });
    }

    /** @param Collection<int, Track> $tracks */
    private function localTrackId(Collection $tracks, ?string $position, ?string $title): ?int
    {
        $titleMatch = $title === null
            ? null
            : $tracks
                ->filter(fn (Track $track): bool => $this->normalized($track->title) === $this->normalized($title))
                ->when(fn (Collection $matches): bool => $matches->count() !== 1, fn (): Collection => collect())
                ->first();
        $numbers = $this->positionNumbers($position);
        if ($numbers !== null) {
            $positionMatch = $tracks->first(fn (Track $track): bool => ($track->disc_number ?? 1) === $numbers['disc']
                && $track->track_number === $numbers['track']);
            if ($positionMatch !== null && ($title === null || $this->normalized($positionMatch->title) === $this->normalized($title))) {
                return $positionMatch->id;
            }
        }

        return $titleMatch?->id;
    }

    /** @return array{disc: int, track: int}|null */
    private function positionNumbers(?string $position): ?array
    {
        if ($position === null) {
            return null;
        }

        $compact = Str::upper(str_replace(' ', '', $position));
        if (preg_match('/^(?:CD|DISC)?(\d+)[.-](\d+)$/', $compact, $matches) === 1) {
            return ['disc' => (int) $matches[1], 'track' => (int) $matches[2]];
        }
        if (preg_match('/^(\d+)$/', $compact, $matches) === 1) {
            return ['disc' => 1, 'track' => (int) $matches[1]];
        }

        return null;
    }

    private function ensureLinkedCopy(Album $album, OwnedAlbumCopy $copy): void
    {
        if ($copy->album_id !== $album->id
            || $copy->provider !== self::PROVIDER
            || $copy->external_release_id === null) {
            throw ValidationException::withMessages([
                'discogs' => 'Select an owned copy that is linked to a Discogs release.',
            ]);
        }
    }

    private function normalized(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function text(mixed $value, ?int $maximumLength = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $maximumLength === null ? $text : mb_substr($text, 0, $maximumLength);
    }
}
