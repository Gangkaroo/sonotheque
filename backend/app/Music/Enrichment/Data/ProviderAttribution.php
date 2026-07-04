<?php

namespace App\Music\Enrichment\Data;

final readonly class ProviderAttribution
{
    public function __construct(
        public string $provider,
        public string $label,
        public ?string $sourceUrl = null,
    ) {
    }

    /** @return array{provider: string, label: string, sourceUrl: ?string} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'sourceUrl' => $this->sourceUrl,
        ];
    }
}
