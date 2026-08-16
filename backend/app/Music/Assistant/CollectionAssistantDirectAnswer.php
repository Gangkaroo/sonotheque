<?php

namespace App\Music\Assistant;

class CollectionAssistantDirectAnswer
{
    /** @var array<string, list<string>> */
    private const METRIC_TERMS = [
        'artists' => ['artist', 'artists', 'interpret', 'interpreten', 'kunstler'],
        'musicians' => ['musician', 'musicians', 'musiker'],
        'albums' => ['album', 'albums', 'alben'],
        'tracks' => ['track', 'tracks', 'song', 'songs', 'titel'],
        'genres' => ['genre', 'genres'],
    ];

    /** @var list<string> */
    private const COUNT_WORDS = [
        'how', 'many', 'what', 'is', 'the', 'number', 'of', 'and', 'or', 'are', 'there',
        'in', 'this', 'my', 'collection', 'library', 'scope', 'do', 'i', 'have', 'contains',
        'contain', 'count', 'wie', 'viele', 'anzahl', 'der', 'von', 'und', 'oder', 'gibt',
        'es', 'dieser', 'diese', 'meiner', 'meine', 'sammlung', 'bibliothek', 'enthalt',
        'habe', 'ich',
    ];

    public function __construct(private readonly CollectionAssistantToolRegistry $tools)
    {
    }

    /**
     * @return null|array{answer: string, toolsUsed: list<string>, references: array{}}
     */
    public function forQuestion(string $question, ?int $libraryRootId, string $locale): ?array
    {
        $words = $this->words($question);
        if (! $this->asksForCount($words)) {
            return null;
        }

        $metrics = $this->metrics($words);
        if ($metrics === [] || $this->hasUnknownWords($words)) {
            return null;
        }

        $result = $this->tools->execute('collection_summary', ['metrics' => $metrics], $libraryRootId);
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];

        return [
            'answer' => $this->formatAnswer($counts, $locale),
            'toolsUsed' => ['collection_summary'],
            'references' => [],
        ];
    }

    /** @param list<string> $words */
    private function asksForCount(array $words): bool
    {
        return array_slice($words, 0, 2) === ['how', 'many']
            || array_slice($words, 0, 4) === ['what', 'is', 'the', 'number']
            || in_array('count', $words, true)
            || array_slice($words, 0, 2) === ['wie', 'viele']
            || in_array('anzahl', $words, true);
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function metrics(array $words): array
    {
        $metrics = [];
        foreach (self::METRIC_TERMS as $metric => $terms) {
            if (array_intersect($words, $terms) !== []) {
                $metrics[] = $metric;
            }
        }

        return $metrics;
    }

    /** @param list<string> $words */
    private function hasUnknownWords(array $words): bool
    {
        $known = self::COUNT_WORDS;
        foreach (self::METRIC_TERMS as $terms) {
            array_push($known, ...$terms);
        }

        return array_diff($words, $known) !== [];
    }

    /** @return list<string> */
    private function words(string $question): array
    {
        $normalized = str_replace(
            ['ä', 'ö', 'ü', 'ß'],
            ['a', 'o', 'u', 'ss'],
            mb_strtolower($question),
        );
        preg_match_all('/[a-z0-9]+/', $normalized, $matches);

        return $matches[0] ?? [];
    }

    /** @param array<string, mixed> $counts */
    private function formatAnswer(array $counts, string $locale): string
    {
        $german = $locale === 'de';
        $parts = [];
        foreach ($counts as $metric => $count) {
            $number = number_format((int) $count, 0, ',', $german ? '.' : ',');
            $parts[] = $number.' '.$this->label($metric, (int) $count, $german);
        }

        $joined = $this->join($parts, $german ? ' und ' : ' and ');

        return $german
            ? 'Der aktuelle Sammlungsbereich enthält '.$joined.'.'
            : 'The current collection scope contains '.$joined.'.';
    }

    private function label(string $metric, int $count, bool $german): string
    {
        if ($german) {
            return match ($metric) {
                'artists' => $count === 1 ? 'Interpret' : 'Interpreten',
                'musicians' => 'Musiker',
                'albums' => $count === 1 ? 'Album' : 'Alben',
                'tracks' => 'Titel',
                'genres' => $count === 1 ? 'Genre' : 'Genres',
                default => $metric,
            };
        }

        $singular = match ($metric) {
            'artists' => 'artist',
            'musicians' => 'musician',
            'albums' => 'album',
            'tracks' => 'track',
            'genres' => 'genre',
            default => $metric,
        };

        return $count === 1 ? $singular : $singular.'s';
    }

    /** @param list<string> $parts */
    private function join(array $parts, string $conjunction): string
    {
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).$conjunction.$last;
    }
}
