<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\AlbumPersonalMetadata;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumPersonalMetadataController extends Controller
{
    public function __construct(private readonly CatalogPayloads $payloads)
    {
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

        $metadata = AlbumPersonalMetadata::query()->updateOrCreate(
            ['album_id' => $album->id],
            [
                'purchase_source' => $this->nullableText($validated['purchaseSource']),
                'purchase_date' => $validated['purchaseDate'],
                'has_physical_copy' => $validated['hasPhysicalCopy'],
                'physical_format' => $validated['hasPhysicalCopy'] ? $validated['physicalFormat'] : null,
                'notes' => $this->nullableText($validated['notes']),
            ],
        );

        return response()->json($this->payloads->albumPersonalMetadata($metadata));
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
