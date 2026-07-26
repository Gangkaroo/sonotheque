<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Library;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnedAlbumCopyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_updates_and_deletes_independent_owned_copies(): void
    {
        $album = $this->createAlbum();

        $firstResponse = $this->postJson("/api/albums/{$album->id}/owned-copies", [
            'isPhysical' => true,
            'physicalFormat' => 'vinyl',
            'purchaseSource' => 'Record store',
            'purchaseDate' => '2024-05-17',
            'purchasePriceAmount' => '29.95',
            'purchasePriceCurrency' => 'eur',
            'mediaCondition' => 'Near Mint',
            'sleeveCondition' => 'Very Good Plus',
            'notes' => 'First pressing',
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'ownedCopies')
            ->assertJsonPath('ownedCopies.0.physicalFormat', 'vinyl')
            ->assertJsonPath('ownedCopies.0.purchasePriceCurrency', 'EUR');
        $firstCopyId = $firstResponse->json('ownedCopies.0.id');

        $secondResponse = $this->postJson("/api/albums/{$album->id}/owned-copies", [
            'isPhysical' => true,
            'physicalFormat' => 'cd',
            'purchaseSource' => 'Online shop',
            'purchaseDate' => null,
            'purchasePriceAmount' => null,
            'purchasePriceCurrency' => null,
            'mediaCondition' => null,
            'sleeveCondition' => null,
            'notes' => null,
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'ownedCopies');
        $secondCopyId = $secondResponse->json('ownedCopies.1.id');

        $this->patchJson("/api/albums/{$album->id}/owned-copies/{$secondCopyId}", [
            'isPhysical' => false,
            'physicalFormat' => 'cd',
            'purchaseSource' => 'Download store',
            'purchaseDate' => null,
            'purchasePriceAmount' => null,
            'purchasePriceCurrency' => null,
            'mediaCondition' => null,
            'sleeveCondition' => null,
            'notes' => 'Lossless download',
        ])
            ->assertOk()
            ->assertJsonPath('ownedCopies.1.isPhysical', false)
            ->assertJsonPath('ownedCopies.1.physicalFormat', null)
            ->assertJsonPath('ownedCopies.1.purchaseSource', 'Download store');

        $this->deleteJson("/api/albums/{$album->id}/owned-copies/{$firstCopyId}")
            ->assertOk()
            ->assertJsonCount(1, 'ownedCopies')
            ->assertJsonPath('ownedCopies.0.id', $secondCopyId);

        $this->assertDatabaseMissing('owned_album_copies', ['id' => $firstCopyId]);
        $this->assertDatabaseHas('owned_album_copies', [
            'id' => $secondCopyId,
            'album_id' => $album->id,
            'is_physical' => false,
            'purchase_source' => 'Download store',
        ]);
    }

    public function test_it_rejects_an_owned_copy_from_another_album(): void
    {
        $album = $this->createAlbum('Album One');
        $otherAlbum = $this->createAlbum('Album Two');
        $copy = $otherAlbum->ownedCopies()->create(['is_physical' => true]);

        $this->deleteJson("/api/albums/{$album->id}/owned-copies/{$copy->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('owned_album_copies', ['id' => $copy->id]);
    }

    public function test_album_notes_are_updated_without_changing_owned_copies(): void
    {
        $album = $this->createAlbum();
        $copy = $album->ownedCopies()->create([
            'is_physical' => true,
            'physical_format' => 'vinyl',
            'purchase_source' => 'Record store',
        ]);

        $response = $this->patchJson("/api/albums/{$album->id}/personal-notes", [
            'notes' => '<p><a href="https://example.com" target="_self">Signed</a> insert</p><script>alert("no")</script>',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ownedCopies.0.id', $copy->id)
            ->assertJsonPath('ownedCopies.0.purchaseSource', 'Record store');

        $notes = (string) $response->json('notes');
        $this->assertStringContainsString('href="https://example.com"', $notes);
        $this->assertStringContainsString('target="_blank"', $notes);
        $this->assertStringContainsString('rel="noopener noreferrer"', $notes);
        $this->assertStringNotContainsString('<script', $notes);

        $this->assertDatabaseHas('owned_album_copies', [
            'id' => $copy->id,
            'physical_format' => 'vinyl',
            'purchase_source' => 'Record store',
        ]);
    }

    private function createAlbum(string $title = 'Album'): Album
    {
        $artistName = 'Artist '.$title;
        $artist = Artist::create([
            'name' => $artistName,
            'sort_name' => $artistName,
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => 'Library '.$title])->roots()->create([
            'name' => 'Music '.$title,
            'path' => 'D:/Music/'.$title,
            'path_hash' => hash('sha256', 'd:/music/'.mb_strtolower($title)),
        ]);

        return Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => $title,
            'sort_title' => $title,
            'relative_path' => $artistName.'/'.$title,
            'relative_path_hash' => hash('sha256', mb_strtolower($artistName.'/'.$title)),
        ]);
    }
}
