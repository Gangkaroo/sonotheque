<?php

namespace Tests\Feature;

use App\Models\LibraryRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibraryRootApiTest extends TestCase
{
    use RefreshDatabase;

    private string $musicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->musicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'music-root-'.Str::uuid();
        mkdir($this->musicPath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->musicPath)) {
            rmdir($this->musicPath);
        }

        parent::tearDown();
    }

    public function test_a_library_root_can_be_created_listed_and_deleted(): void
    {
        $response = $this->postJson('/api/library_roots', [
            'name' => 'Main collection',
            'path' => $this->musicPath,
            'coverImagePath' => 'artwork\\front.jpg',
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $rootId = $response->json('id');

        $response
            ->assertJsonPath('name', 'Main collection')
            ->assertJsonPath('coverImagePath', 'artwork/front.jpg')
            ->assertJsonMissingPath('pathHash');

        $this->get('/api/library_roots', ['Accept' => 'application/ld+json'])
            ->assertOk()
            ->assertJsonCount(1, 'member')
            ->assertJsonPath('member.0.path', str_replace('\\', '/', realpath($this->musicPath)));

        $this->delete('/api/library_roots/'.$rootId, headers: ['Accept' => 'application/ld+json'])
            ->assertNoContent();

        $this->assertDatabaseCount('library_roots', 0);
    }

    public function test_root_creation_rejects_missing_duplicate_and_unsafe_paths(): void
    {
        $this->postJson('/api/library_roots', [
            'name' => 'Missing',
            'path' => $this->musicPath.'-missing',
            'coverImagePath' => 'cover.jpg',
        ], ['Accept' => 'application/ld+json'])->assertUnprocessable();

        $this->postJson('/api/library_roots', [
            'name' => 'Main collection',
            'path' => $this->musicPath,
            'coverImagePath' => 'cover.jpg',
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $this->postJson('/api/library_roots', [
            'name' => 'Duplicate',
            'path' => $this->musicPath,
            'coverImagePath' => 'cover.jpg',
        ], ['Accept' => 'application/ld+json'])->assertUnprocessable();

        $unsafePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'unsafe-root-'.Str::uuid();
        mkdir($unsafePath);

        try {
            $this->postJson('/api/library_roots', [
                'name' => 'Unsafe cover',
                'path' => $unsafePath,
                'coverImagePath' => '../cover.jpg',
            ], ['Accept' => 'application/ld+json'])->assertUnprocessable();
        } finally {
            rmdir($unsafePath);
        }

        $this->assertSame(1, LibraryRoot::count());
    }
}
