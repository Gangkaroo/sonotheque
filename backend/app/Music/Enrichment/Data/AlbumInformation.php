<?php

namespace App\Music\Enrichment\Data;

final readonly class AlbumInformation
{
    /**
     * @param  list<string>  $tags
     * @param  list<array{name: string, catalogNumber: ?string}>  $recordLabels
     */
    public function __construct(
        public string $title,
        public string $artistName,
        public ?string $summary,
        public ?string $releaseDate,
        public ?string $label,
        public ?string $releaseType,
        public array $tags,
        public ProviderAttribution $attribution,
        public ?string $providerReference = null,
        public ?string $matchMethod = null,
        public ?int $matchConfidence = null,
        public array $recordLabels = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'artistName' => $this->artistName,
            'summary' => $this->summary,
            'releaseDate' => $this->releaseDate,
            'label' => $this->label,
            'releaseType' => $this->releaseType,
            'tags' => $this->tags,
            'attribution' => $this->attribution->toArray(),
            'providerReference' => $this->providerReference,
            'matchMethod' => $this->matchMethod,
            'matchConfidence' => $this->matchConfidence,
            'recordLabels' => $this->recordLabels,
        ];
    }
}
