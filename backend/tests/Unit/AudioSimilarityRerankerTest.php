<?php

namespace Tests\Unit;

use App\Music\Intelligence\AudioSimilarityReranker;
use PHPUnit\Framework\TestCase;

class AudioSimilarityRerankerTest extends TestCase
{
    public function test_it_reranks_a_bounded_pool_without_overriding_missing_features(): void
    {
        $reranker = new AudioSimilarityReranker();
        $preferences = [
            'enabled' => true,
            'tempoInfluence' => 10,
            'keyInfluence' => 0,
            'intensityInfluence' => 0,
        ];

        $this->assertSame(100, $reranker->candidateLimit(25, $preferences));
        $ranked = $reranker->rerank(
            ['features' => ['bpm' => 120]],
            collect([
                [
                    'id' => 1,
                    'similarity' => 0.99,
                    'features' => ['bpm' => 169.7],
                ],
                [
                    'id' => 2,
                    'similarity' => 0.98,
                    'features' => ['bpm' => 120],
                ],
                [
                    'id' => 3,
                    'similarity' => 0.97,
                    'features' => [],
                ],
            ]),
            $preferences,
        );

        $this->assertSame([2, 3, 1], $ranked->pluck('id')->all());
        $this->assertSame(0.97, $ranked->firstWhere('id', 3)['rankingScore']);
        $this->assertNull($ranked->firstWhere('id', 3)['featureCompatibility']['tempo']);
    }

    public function test_half_and_double_tempo_are_compatible_and_disabled_mode_is_stable(): void
    {
        $reranker = new AudioSimilarityReranker();
        $enabled = [
            'enabled' => true,
            'tempoInfluence' => 10,
            'keyInfluence' => 0,
            'intensityInfluence' => 0,
        ];
        $matches = collect([
            ['id' => 1, 'similarity' => 0.9, 'features' => ['bpm' => 60]],
            ['id' => 2, 'similarity' => 0.8, 'features' => ['bpm' => 240]],
        ]);

        $ranked = $reranker->rerank(['features' => ['bpm' => 120]], $matches, $enabled);

        $this->assertSame(1.0, $ranked[0]['featureCompatibility']['tempo']);
        $this->assertSame(1.0, $ranked[1]['featureCompatibility']['tempo']);
        $this->assertSame(0.9, $ranked[0]['rankingScore']);
        $this->assertSame(2, $reranker->candidateLimit(2, [
            ...$enabled,
            'enabled' => false,
        ]));
    }
}
