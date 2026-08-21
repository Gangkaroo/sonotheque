<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Music\Catalog\GenreResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reuses_a_genre_case_insensitively(): void
    {
        $existing = Genre::query()->create(['name' => 'Psychedelic Rock']);

        $resolved = $this->app->make(GenreResolver::class)->resolve('psychedelic rock');

        $this->assertTrue($existing->is($resolved));
        $this->assertSame(1, Genre::query()->count());
    }

    public function test_it_reuses_a_genre_after_creating_it(): void
    {
        $resolver = $this->app->make(GenreResolver::class);

        $first = $resolver->resolve('Retro Rock');
        $second = $resolver->resolve('Retro Rock');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Genre::query()->count());
    }
}
