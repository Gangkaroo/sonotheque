<?php

namespace App\Music\Catalog;

use App\Enums\MediaFileStatus;
use App\Models\Album;
use App\Models\AlbumRecordLabel;
use App\Models\RecordLabel;
use Illuminate\Support\Facades\DB;

final class RecordLabelImporter
{
    public const FILE_TAG_SOURCE = 'file_tag';

    public function __construct(
        private readonly RecordLabelTagReader $reader,
        private readonly RecordLabelNormalizer $normalizer,
    ) {
    }

    public function syncAlbum(Album|int $album): int
    {
        $albumId = $album instanceof Album ? $album->id : $album;

        return $this->syncAlbums([$albumId]);
    }

    /**
     * @param  list<array{name: string, catalogNumber: ?string, source: string, sourceReference: string}>  $assignments
     */
    public function syncProviderAssignments(Album|int $album, array $assignments): int
    {
        $albumId = $album instanceof Album ? $album->id : $album;

        return DB::transaction(function () use ($albumId, $assignments): int {
            $existing = AlbumRecordLabel::query()
                ->where('album_id', $albumId)
                ->where('source', '!=', self::FILE_TAG_SOURCE)
                ->get();
            $changes = $existing->count();
            AlbumRecordLabel::query()->whereKey($existing->modelKeys())->delete();

            foreach (collect($assignments)->unique(function (array $assignment): string {
                return $assignment['source'].'|'.$this->normalizer->normalizedName($assignment['name'])
                    .'|'.$this->normalizer->catalogNumberHash($assignment['catalogNumber']);
            }) as $assignment) {
                $name = $this->normalizer->displayName($assignment['name']);
                if ($name === '') {
                    continue;
                }
                $catalogNumber = $this->normalizer->catalogNumber($assignment['catalogNumber']);
                $recordLabel = RecordLabel::query()->firstOrCreate(
                    ['normalized_name' => $this->normalizer->normalizedName($name)],
                    ['name' => $name],
                );
                AlbumRecordLabel::create([
                    'album_id' => $albumId,
                    'record_label_id' => $recordLabel->id,
                    'catalog_number' => $catalogNumber,
                    'catalog_number_hash' => $this->normalizer->catalogNumberHash($catalogNumber),
                    'source' => $assignment['source'],
                    'source_reference' => $assignment['sourceReference'],
                ]);
                $changes++;
            }

            RecordLabel::query()->whereDoesntHave('albumAssignments')->delete();

            return $changes;
        });
    }

    /**
     * @param  list<array{name: string, catalogNumber: ?string, source: string, sourceReference: string}>  $assignments
     */
    public function confirmProviderAssignments(Album|int $album, array $assignments): int
    {
        $albumId = $album instanceof Album ? $album->id : $album;

        return DB::transaction(function () use ($albumId, $assignments): int {
            $changes = 0;

            foreach (collect($assignments)->unique(function (array $assignment): string {
                return $assignment['source'].'|'.$this->normalizer->normalizedName($assignment['name'])
                    .'|'.$this->normalizer->catalogNumberHash($assignment['catalogNumber']);
            }) as $assignment) {
                $name = $this->normalizer->displayName($assignment['name']);
                if ($name === '') {
                    continue;
                }

                $catalogNumber = $this->normalizer->catalogNumber($assignment['catalogNumber']);
                $recordLabel = RecordLabel::query()->firstOrCreate(
                    ['normalized_name' => $this->normalizer->normalizedName($name)],
                    ['name' => $name],
                );
                $providerAssignment = AlbumRecordLabel::query()->firstOrNew([
                    'album_id' => $albumId,
                    'record_label_id' => $recordLabel->id,
                    'catalog_number_hash' => $this->normalizer->catalogNumberHash($catalogNumber),
                    'source' => $assignment['source'],
                ]);
                $changed = ! $providerAssignment->exists
                    || $providerAssignment->catalog_number !== $catalogNumber
                    || $providerAssignment->source_reference !== $assignment['sourceReference'];
                $providerAssignment->fill([
                    'catalog_number' => $catalogNumber,
                    'source_reference' => $assignment['sourceReference'],
                ])->save();
                $changes += $changed ? 1 : 0;
            }

            return $changes;
        });
    }

    /** @param list<int> $albumIds */
    public function syncAlbums(array $albumIds): int
    {
        $albumIds = array_values(array_unique(array_map('intval', $albumIds)));
        $changes = 0;

        foreach (array_chunk($albumIds, 500) as $chunk) {
            $desiredByAlbum = array_fill_keys($chunk, []);
            $mediaFiles = DB::table('media_files')
                ->whereIn('album_id', $chunk)
                ->where('status', MediaFileStatus::Available->value)
                ->orderBy('id')
                ->lazyById(500, 'id');

            foreach ($mediaFiles as $mediaFile) {
                $rawMetadata = is_string($mediaFile->raw_metadata)
                    ? json_decode($mediaFile->raw_metadata, true)
                    : $mediaFile->raw_metadata;
                if (! is_array($rawMetadata)) {
                    continue;
                }

                foreach ($this->reader->read($rawMetadata)->items as $item) {
                    $name = $this->normalizer->displayName($item->name);
                    if ($name === '') {
                        continue;
                    }
                    $catalogNumber = $this->normalizer->catalogNumber($item->catalogNumber);
                    $identity = $this->normalizer->normalizedName($name)
                        .'|'.$this->normalizer->catalogNumberHash($catalogNumber);
                    $desiredByAlbum[(int) $mediaFile->album_id][$identity] ??= compact('name', 'catalogNumber');
                }
            }

            $changes += $this->syncChunk($chunk, $desiredByAlbum);
        }

        if ($changes > 0) {
            RecordLabel::query()->whereDoesntHave('albumAssignments')->delete();
        }

        return $changes;
    }

    /**
     * @param  list<int>  $albumIds
     * @param  array<int, array<string, array{name: string, catalogNumber: ?string}>>  $desiredByAlbum
     */
    private function syncChunk(array $albumIds, array $desiredByAlbum): int
    {
        return DB::transaction(function () use ($albumIds, $desiredByAlbum): int {
            $existing = AlbumRecordLabel::query()
                ->whereIn('album_id', $albumIds)
                ->where('source', self::FILE_TAG_SOURCE)
                ->with('recordLabel:id,normalized_name')
                ->get();
            $existingByAlbum = $existing->groupBy('album_id');
            $staleIds = [];
            $changes = 0;

            foreach ($albumIds as $albumId) {
                $assignments = $existingByAlbum->get($albumId, collect());
                $existingByIdentity = $assignments->keyBy(
                    fn (AlbumRecordLabel $assignment): string => $assignment->recordLabel->normalized_name
                        .'|'.$assignment->catalog_number_hash,
                );
                $keptIds = [];

                foreach ($desiredByAlbum[$albumId] as $identity => $item) {
                    $assignment = $existingByIdentity->get($identity);
                    if ($assignment !== null) {
                        $keptIds[] = $assignment->id;

                        continue;
                    }

                    $normalizedName = $this->normalizer->normalizedName($item['name']);
                    $recordLabel = RecordLabel::query()->firstOrCreate(
                        ['normalized_name' => $normalizedName],
                        ['name' => $item['name']],
                    );
                    $created = AlbumRecordLabel::create([
                        'album_id' => $albumId,
                        'record_label_id' => $recordLabel->id,
                        'catalog_number' => $item['catalogNumber'],
                        'catalog_number_hash' => $this->normalizer->catalogNumberHash($item['catalogNumber']),
                        'source' => self::FILE_TAG_SOURCE,
                        'source_reference' => null,
                    ]);
                    $keptIds[] = $created->id;
                    $changes++;
                }

                foreach ($assignments as $assignment) {
                    if (! in_array($assignment->id, $keptIds, true)) {
                        $staleIds[] = $assignment->id;
                    }
                }
            }

            $changes += count($staleIds);
            if ($staleIds !== []) {
                AlbumRecordLabel::query()->whereKey($staleIds)->delete();
            }

            return $changes;
        });
    }
}
