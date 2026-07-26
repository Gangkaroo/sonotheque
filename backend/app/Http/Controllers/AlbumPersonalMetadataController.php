<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\AlbumPersonalMetadata;
use App\Support\AlbumNotesSanitizer;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlbumPersonalMetadataController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly AlbumNotesSanitizer $notesSanitizer,
    ) {
    }

    public function update(Request $request, Album $album): JsonResponse
    {
        $validated = $request->validate([
            'purchaseSource' => ['present', 'nullable', 'string', 'max:255'],
            'purchaseDate' => ['present', 'nullable', 'date_format:Y-m-d'],
            'hasPhysicalCopy' => ['present', 'boolean'],
            'physicalFormat' => ['present', 'nullable', 'string', 'in:vinyl,cd,blu_ray,dvd,cassette'],
            'notes' => ['present', 'nullable', 'string', 'max:10000'],
        ]);

        DB::transaction(function () use ($album, $validated): void {
            AlbumPersonalMetadata::query()->updateOrCreate(
                ['album_id' => $album->id],
                ['notes' => $this->notesSanitizer->sanitize($validated['notes'])],
            );

            $purchaseSource = $this->nullableText($validated['purchaseSource']);
            $hasOwnedCopy = $validated['hasPhysicalCopy']
                || $purchaseSource !== null
                || $validated['purchaseDate'] !== null;
            $ownedCopy = $album->ownedCopies()->first();

            if (! $hasOwnedCopy) {
                $ownedCopy?->delete();

                return;
            }

            $values = [
                'is_physical' => $validated['hasPhysicalCopy'],
                'physical_format' => $validated['hasPhysicalCopy'] ? $validated['physicalFormat'] : null,
                'purchase_source' => $purchaseSource,
                'purchase_date' => $validated['purchaseDate'],
            ];

            if ($ownedCopy) {
                $ownedCopy->update($values);
            } else {
                $album->ownedCopies()->create($values);
            }
        });

        $album->load(['personalMetadata', 'ownedCopies']);

        return response()->json($this->payloads->albumPersonalMetadata($album));
    }

    public function updateNotes(Request $request, Album $album): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['present', 'nullable', 'string', 'max:10000'],
        ]);

        AlbumPersonalMetadata::query()->updateOrCreate(
            ['album_id' => $album->id],
            ['notes' => $this->notesSanitizer->sanitize($validated['notes'])],
        );
        $album->load(['personalMetadata', 'ownedCopies']);

        return response()->json($this->payloads->albumPersonalMetadata($album));
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
