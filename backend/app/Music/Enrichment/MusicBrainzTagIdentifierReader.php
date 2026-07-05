<?php

namespace App\Music\Enrichment;

class MusicBrainzTagIdentifierReader
{
    /** @return array<string, string> */
    public function read(array $rawMetadata): array
    {
        $comments = $this->array($rawMetadata['comments'] ?? null);
        $id3Comments = $this->array($rawMetadata['id3v2']['comments'] ?? null);
        $sources = [
            $comments,
            $this->array($comments['text'] ?? null),
            $id3Comments,
            $this->array($id3Comments['text'] ?? null),
        ];
        $identifiers = [];

        foreach ([
            'artist' => ['musicbrainzartistid'],
            'albumArtist' => ['musicbrainzalbumartistid'],
            'release' => ['musicbrainzalbumid', 'musicbrainzreleaseid'],
            'releaseGroup' => ['musicbrainzreleasegroupid'],
            'recording' => ['musicbrainztrackid', 'musicbrainzrecordingid'],
            'releaseTrack' => ['musicbrainzreleasetrackid'],
        ] as $name => $keys) {
            $identifier = $this->identifier($sources, $keys);
            if ($identifier !== null) {
                $identifiers[$name] = $identifier;
            }
        }

        return $identifiers;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param list<string> $keys
     */
    private function identifier(array $sources, array $keys): ?string
    {
        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if (! is_string($key) || ! in_array($this->normalizedKey($key), $keys, true)) {
                    continue;
                }

                foreach ((array) $value as $candidate) {
                    $candidate = strtolower(trim((string) $candidate));
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $candidate) === 1) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function normalizedKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?? '';
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
