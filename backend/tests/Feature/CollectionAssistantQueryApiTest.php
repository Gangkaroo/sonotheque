<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\ApplicationSetting;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\Track;
use App\Models\TrackPlayStatistic;
use App\Music\Assistant\CollectionAssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CollectionAssistantQueryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sonotheque.collection_assistant.ollama_url', 'http://ollama.test:11434');
    }

    public function test_enabled_assistant_executes_a_scoped_tool_call_and_returns_the_answer(): void
    {
        $root = $this->createCatalogTrack();
        ApplicationSetting::current()->update([
            'collection_assistant_enabled' => true,
            'collection_assistant_model' => 'qwen3:8b',
        ]);
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'collection_summary',
                                'arguments' => ['metrics' => ['albums', 'tracks']],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'This library root contains one album and one track.',
                    ],
                ]),
        ]);

        $this->postJson('/api/assistant/query', [
            'question' => 'How large is this part of my collection?',
            'libraryRoot' => $root->id,
            'history' => [
                ['role' => 'user', 'content' => 'Only use the selected root.'],
                ['role' => 'assistant', 'content' => 'Understood.'],
            ],
        ])->assertOk()
            ->assertExactJson([
                'answer' => 'This library root contains one album and one track.',
                'toolsUsed' => ['collection_summary'],
                'references' => [],
            ]);

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertNotEmpty($requests[0][0]['tools']);
        $this->assertSame([], $requests[1][0]['tools']);
        $this->assertSame('15m', $requests[0][0]['keep_alive']);
        $this->assertSame(4096, $requests[0][0]['options']['num_ctx']);
        $this->assertSame(256, $requests[1][0]['options']['num_predict']);
        Http::assertSent(function (Request $request): bool {
            $toolMessage = collect($request['messages'] ?? [])->firstWhere('role', 'tool');
            $assistantMessage = collect($request['messages'] ?? [])->first(
                fn (mixed $message): bool => is_array($message)
                    && ($message['role'] ?? null) === 'assistant'
                    && isset($message['tool_calls']),
            );
            if (! is_array($toolMessage) || ! is_array($assistantMessage)) {
                return false;
            }
            $result = json_decode($toolMessage['content'] ?? '', true);
            $historyMessage = collect($request['messages'] ?? [])->firstWhere(
                'content',
                'Only use the selected root.',
            );

            return is_array($historyMessage)
                && ($historyMessage['role'] ?? null) === 'user'
                && data_get($assistantMessage, 'tool_calls.0.function.arguments.metrics') === ['albums', 'tracks']
                && data_get($result, 'scope.name') === 'Assistant root'
                && data_get($result, 'counts.albums') === 1
                && data_get($result, 'counts.tracks') === 1;
        });
    }

    public function test_disabled_assistant_does_not_contact_the_provider(): void
    {
        Http::fake();

        $this->postJson('/api/assistant/query', [
            'question' => 'How many albums do I have?',
        ])->assertStatus(409)
            ->assertJsonPath('errorCode', 'assistant_disabled');

        Http::assertNothingSent();
    }

    public function test_playback_preview_is_returned_to_the_client_but_not_to_the_model(): void
    {
        ApplicationSetting::current()->update([
            'collection_assistant_enabled' => true,
            'collection_assistant_model' => 'qwen3:8b',
        ]);
        $action = [
            'type' => 'track_queue',
            'mode' => 'play',
            'scope' => ['id' => null, 'name' => 'All library roots'],
            'tracks' => [[
                'id' => 42,
                'title' => 'Verified track',
                'available' => true,
                'streamUrl' => '/api/tracks/42/stream',
                'durationMs' => 180000,
                'trackNumber' => 1,
                'discNumber' => 1,
                'year' => 2026,
                'album' => null,
                'artists' => [],
                'playStatistics' => [
                    'playCount' => 0,
                    'firstPlayedAt' => null,
                    'lastPlayedAt' => null,
                ],
            ]],
        ];
        $tools = Mockery::mock(CollectionAssistantToolRegistry::class);
        $tools->shouldReceive('definitions')->once()->andReturn([[
            'type' => 'function',
            'function' => ['name' => 'find_similar_tracks'],
        ]]);
        $tools->shouldReceive('execute')
            ->once()
            ->with('find_similar_tracks', ['title' => 'Verified track', 'action' => 'play'], null)
            ->andReturn([
                'status' => 'ok',
                'action' => $action,
            ]);
        $this->app->instance(CollectionAssistantToolRegistry::class, $tools);
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'find_similar_tracks',
                                'arguments' => [
                                    'title' => 'Verified track',
                                    'action' => 'play',
                                ],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I prepared a playback preview.',
                    ],
                ]),
        ]);

        $this->postJson('/api/assistant/query', [
            'question' => 'Play music similar to Verified track.',
        ])->assertOk()
            ->assertJsonPath('action.type', 'track_queue')
            ->assertJsonPath('action.mode', 'play')
            ->assertJsonPath('action.tracks.0.id', 42);

        Http::assertSent(function (Request $request): bool {
            $toolMessage = collect($request['messages'] ?? [])->firstWhere('role', 'tool');
            if (! is_array($toolMessage)) {
                return false;
            }

            $toolResult = json_decode($toolMessage['content'] ?? '', true);

            return is_array($toolResult) && ! array_key_exists('action', $toolResult);
        });
    }

    public function test_simple_collection_count_question_bypasses_the_model(): void
    {
        $root = $this->createCatalogTrack();
        ApplicationSetting::current()->update([
            'collection_assistant_enabled' => true,
            'collection_assistant_model' => 'qwen3:8b',
        ]);
        Http::fake();

        $this->postJson('/api/assistant/query', [
            'question' => 'Wie viele Alben und Titel enthält diese Sammlung?',
            'libraryRoot' => $root->id,
            'locale' => 'de',
        ])->assertOk()
            ->assertExactJson([
                'answer' => 'Der aktuelle Sammlungsbereich enthält 1 Album und 1 Titel.',
                'toolsUsed' => ['collection_summary'],
                'references' => [],
            ]);

        Http::assertNothingSent();
    }

    public function test_qualified_count_question_still_uses_the_model(): void
    {
        $this->createCatalogTrack();
        ApplicationSetting::current()->update([
            'collection_assistant_enabled' => true,
            'collection_assistant_model' => 'qwen3:8b',
        ]);
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => 'There is one album by Assistant Artist.',
                ],
            ]),
        ]);

        $this->postJson('/api/assistant/query', [
            'question' => 'How many albums by Assistant Artist do I have?',
        ])->assertOk()
            ->assertJsonPath('answer', 'There is one album by Assistant Artist.');

        Http::assertSentCount(1);
    }

    public function test_catalog_tool_results_return_verified_navigation_references(): void
    {
        $root = $this->createCatalogTrack();
        $album = Album::query()->firstOrFail();
        ApplicationSetting::current()->update([
            'collection_assistant_enabled' => true,
            'collection_assistant_model' => 'qwen3:8b',
        ]);
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'search_albums_by_artist',
                                'arguments' => ['artist_name' => 'Assistant Artist'],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I found one album.',
                    ],
                ]),
        ]);

        $this->postJson('/api/assistant/query', [
            'question' => 'Find albums by Assistant Artist.',
            'libraryRoot' => $root->id,
        ])->assertOk()
            ->assertJsonPath('references.0.path', '/albums/'.$album->id)
            ->assertJsonPath('references.0.label', 'Assistant Artist - Assistant Album');
    }

    public function test_assistant_can_rank_most_played_genres(): void
    {
        $root = $this->createCatalogTrack();
        $track = Track::query()->firstOrFail();
        $genre = Genre::create(['name' => 'Progressive Rock']);
        $track->genres()->attach($genre);
        TrackPlayStatistic::create([
            'track_id' => $track->id,
            'play_count' => 12,
            'last_played_at' => now(),
        ]);
        ApplicationSetting::current()->update([
            'collection_assistant_enabled' => true,
            'collection_assistant_model' => 'qwen3:8b',
        ]);
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::sequence()
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'top_listened',
                                'arguments' => [
                                    'entity_type' => 'genres',
                                    'period' => 'all_time',
                                    'limit' => 5,
                                ],
                            ],
                        ]],
                    ],
                ])
                ->push([
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Progressive Rock is your most-played genre.',
                    ],
                ]),
        ]);

        $this->postJson('/api/assistant/query', [
            'question' => 'What are my most-played genres?',
            'libraryRoot' => $root->id,
        ])->assertOk()
            ->assertJsonPath('toolsUsed.0', 'top_listened')
            ->assertJsonPath('references.0.path', '/albums?genre='.$genre->id)
            ->assertJsonPath('references.0.label', 'Progressive Rock');
    }

    private function createCatalogTrack(): \App\Models\LibraryRoot
    {
        $artist = Artist::create([
            'name' => 'Assistant Artist',
            'sort_name' => 'Assistant Artist',
            'browse_initial' => 'A',
        ]);
        $root = Library::create(['name' => 'Assistant library'])->roots()->create([
            'name' => 'Assistant root',
            'path' => 'D:/Assistant',
            'path_hash' => hash('sha256', 'd:/assistant'),
        ]);
        $album = Album::create([
            'library_root_id' => $root->id,
            'primary_artist_id' => $artist->id,
            'title' => 'Assistant Album',
            'sort_title' => 'Assistant Album',
            'relative_path' => 'Assistant Artist/Assistant Album',
            'relative_path_hash' => hash('sha256', 'assistant artist/assistant album'),
        ]);
        $mediaFile = MediaFile::create([
            'library_root_id' => $root->id,
            'album_id' => $album->id,
            'relative_path' => 'Assistant Artist/Assistant Album/Track.mp3',
            'relative_path_hash' => hash('sha256', 'assistant artist/assistant album/track.mp3'),
            'file_size' => 1,
            'modified_at' => now(),
            'last_seen_at' => now(),
        ]);
        $track = Track::create([
            'album_id' => $album->id,
            'media_file_id' => $mediaFile->id,
            'title' => 'Assistant Track',
            'sort_title' => 'Assistant Track',
        ]);
        $track->artists()->attach($artist, ['role' => 'primary', 'position' => 0]);

        return $root;
    }
}
