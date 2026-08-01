<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\OwnedAlbumCopy;
use App\Music\Enrichment\DiscogsMusicianCreditImporter;
use App\Support\CatalogPayloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnedAlbumCopyController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly DiscogsMusicianCreditImporter $musicianCredits,
    ) {
    }

    public function store(Request $request, Album $album): JsonResponse
    {
        $album->ownedCopies()->create($this->values($request));

        return response()->json($this->personalMetadata($album), 201);
    }

    public function update(Request $request, Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        $ownedAlbumCopy->update($this->values($request));

        return response()->json($this->personalMetadata($album));
    }

    public function destroy(Album $album, OwnedAlbumCopy $ownedAlbumCopy): JsonResponse
    {
        $this->ensureCopyBelongsToAlbum($album, $ownedAlbumCopy);
        $this->musicianCredits->clearIfSelected($album, $ownedAlbumCopy);
        $ownedAlbumCopy->delete();

        return response()->json($this->personalMetadata($album));
    }

    /** @return array<string, mixed> */
    private function values(Request $request): array
    {
        $validated = $request->validate([
            'isPhysical' => ['required', 'boolean'],
            'physicalFormat' => ['nullable', 'string', 'in:vinyl,cd,blu_ray,dvd,cassette'],
            'purchaseSource' => ['nullable', 'string', 'max:255'],
            'purchaseDate' => ['nullable', 'date_format:Y-m-d'],
            'purchasePriceAmount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'purchasePriceCurrency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'mediaCondition' => ['nullable', 'string', 'max:32'],
            'sleeveCondition' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        return [
            'is_physical' => $validated['isPhysical'],
            'physical_format' => $validated['isPhysical'] ? $validated['physicalFormat'] : null,
            'purchase_source' => $this->nullableText($validated['purchaseSource']),
            'purchase_date' => $validated['purchaseDate'],
            'purchase_price_amount' => $validated['purchasePriceAmount'],
            'purchase_price_currency' => $this->currency($validated['purchasePriceCurrency']),
            'media_condition' => $this->nullableText($validated['mediaCondition']),
            'sleeve_condition' => $this->nullableText($validated['sleeveCondition']),
            'notes' => $this->nullableText($validated['notes']),
        ];
    }

    private function ensureCopyBelongsToAlbum(Album $album, OwnedAlbumCopy $ownedAlbumCopy): void
    {
        abort_unless($ownedAlbumCopy->album_id === $album->id, 404);
    }

    /** @return array<string, mixed> */
    private function personalMetadata(Album $album): array
    {
        $album->load(['personalMetadata', 'ownedCopies']);

        return $this->payloads->albumPersonalMetadata($album);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function currency(mixed $value): ?string
    {
        $value = $this->nullableText($value);

        return $value === null ? null : strtoupper($value);
    }
}
