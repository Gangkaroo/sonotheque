<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class AlbumNotesSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https'])
                ->forceAttribute('a', 'target', '_blank')
                ->forceAttribute('a', 'rel', 'noopener noreferrer'),
        );
    }

    public function sanitize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $sanitized = trim($this->sanitizer->sanitize($value));
        $plainText = trim(html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_HTML5));

        return $plainText === '' ? null : $sanitized;
    }
}
