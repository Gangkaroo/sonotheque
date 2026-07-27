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
            'coverImagePaths' => ['artwork\\front.jpg', '..\\Cover\\Front.jpg', 'Disc 1\\Front.jpg'],
            'excludedDirectories' => ['Incoming', 'Downloads\\Incomplete'],
            'watchEnabled' => true,
            'watchPollIntervalMinutes' => 10,
            'watchReconcileIntervalMinutes' => 720,
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $rootId = $response->json('id');

        $response
            ->assertJsonPath('name', 'Main collection')
            ->assertJsonPath('coverImagePaths.0', 'artwork/front.jpg')
            ->assertJsonPath('coverImagePaths.1', '../Cover/Front.jpg')
            ->assertJsonPath('coverImagePaths.2', 'Disc 1/Front.jpg')
            ->assertJsonPath('excludedDirectories.1', 'Downloads/Incomplete')
            ->assertJsonPath('watchEnabled', true)
            ->assertJsonPath('watchPollIntervalMinutes', 10)
            ->assertJsonPath('watchReconcileIntervalMinutes', 720)
            ->assertJsonPath('watchStatus', 'pending')
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
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])->assertUnprocessable();

        $this->postJson('/api/library_roots', [
            'name' => 'Main collection',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $this->postJson('/api/library_roots', [
            'name' => 'Duplicate',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])->assertUnprocessable();

        $unsafePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'unsafe-root-'.Str::uuid();
        mkdir($unsafePath);

        try {
            $this->postJson('/api/library_roots', [
                'name' => 'Unsafe cover',
                'path' => $unsafePath,
                'coverImagePaths' => ['/outside/cover.jpg'],
            ], ['Accept' => 'application/ld+json'])->assertUnprocessable();
        } finally {
            rmdir($unsafePath);
        }

        $this->assertSame(1, LibraryRoot::count());
    }

    public function test_root_creation_rejects_overlapping_folders(): void
    {
        $artistPath = $this->musicPath.DIRECTORY_SEPARATOR.'Artist';
        mkdir($artistPath);

        $this->postJson('/api/library_roots', [
            'name' => 'Main collection',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $this->postJson('/api/library_roots', [
            'name' => 'Nested collection',
            'path' => $artistPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])
            ->assertUnprocessable()
            ->assertJsonPath('violations.0.propertyPath', 'path');

        $this->delete('/api/library_roots/'.LibraryRoot::firstOrFail()->id, headers: ['Accept' => 'application/ld+json'])
            ->assertNoContent();

        $this->postJson('/api/library_roots', [
            'name' => 'Nested collection',
            'path' => $artistPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $this->postJson('/api/library_roots', [
            'name' => 'Parent collection',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])
            ->assertUnprocessable()
            ->assertJsonPath('violations.0.propertyPath', 'path');

        LibraryRoot::query()->delete();
        rmdir($artistPath);
    }

    public function test_a_library_root_name_and_cover_path_can_be_updated(): void
    {
        $root = $this->postJson('/api/library_roots', [
            'name' => 'Main collection',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg'],
        ], ['Accept' => 'application/ld+json'])->assertCreated();

        $this->call(
            'PATCH',
            '/api/library_roots/'.$root->json('id'),
            server: [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_ACCEPT' => 'application/ld+json',
            ],
            content: json_encode([
                'name' => 'Archive',
                'coverImagePaths' => ['artwork\\front.jpg', 'Disc 1\\Front.jpg'],
                'excludedDirectories' => ['Incoming'],
                'watchEnabled' => true,
                'watchPollIntervalMinutes' => 15,
                'watchReconcileIntervalMinutes' => 1440,
            ], JSON_THROW_ON_ERROR),
        )
            ->assertOk()
            ->assertJsonPath('name', 'Archive')
            ->assertJsonPath('coverImagePaths.1', 'Disc 1/Front.jpg')
            ->assertJsonPath('excludedDirectories.0', 'Incoming')
            ->assertJsonPath('watchEnabled', true)
            ->assertJsonPath('watchPollIntervalMinutes', 15)
            ->assertJsonPath('watchStatus', 'pending')
            ->assertJsonPath('path', str_replace('\\', '/', realpath($this->musicPath)));
    }

    public function test_root_configuration_rejects_unsafe_cover_and_excluded_folder_paths(): void
    {
        $this->postJson('/api/library_roots', [
            'name' => 'Unsafe cover',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg', '/outside/front.jpg'],
            'excludedDirectories' => [],
        ], ['Accept' => 'application/ld+json'])
            ->assertUnprocessable()
            ->assertJsonPath('violations.0.propertyPath', 'coverImagePaths');

        $this->postJson('/api/library_roots', [
            'name' => 'Unsafe exclusion',
            'path' => $this->musicPath,
            'coverImagePaths' => ['cover.jpg'],
            'excludedDirectories' => ['../Other'],
        ], ['Accept' => 'application/ld+json'])
            ->assertUnprocessable()
            ->assertJsonPath('violations.0.propertyPath', 'excludedDirectories');
    }
}
