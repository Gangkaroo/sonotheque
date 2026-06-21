<?php

namespace Tests\Feature;

use App\Enums\ArtworkSource;
use App\Models\Artwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtworkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_artwork_endpoint_streams_the_cached_source_image(): void
    {
        Storage::fake('artwork');
        Storage::disk('artwork')->put('originals/example.jpg', 'fake image bytes');

        $artwork = Artwork::create([
            'source_type' => ArtworkSource::Folder,
            'source_relative_path' => 'Cover/Front.jpg',
            'cache_path' => 'originals/example.jpg',
            'thumbnail_path' => 'thumbnails/example.webp',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', 'example artwork'),
        ]);

        $this->get("/api/artwork/{$artwork->id}/original")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }
}
