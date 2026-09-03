<?php

namespace App\Music\Catalog;

final class RecordLabelNormalizer
{
    public function displayName(string $name): string
    {
        return mb_substr($this->collapseWhitespace($name), 0, 255);
    }

    public function normalizedName(string $name): string
    {
        return mb_strtolower($this->displayName($name));
    }

    public function catalogNumber(?string $catalogNumber): ?string
    {
        if ($catalogNumber === null) {
            return null;
        }

        $catalogNumber = mb_substr($this->collapseWhitespace($catalogNumber), 0, 128);

        return $catalogNumber === '' ? null : $catalogNumber;
    }

    public function catalogNumberHash(?string $catalogNumber): string
    {
        return hash('sha256', mb_strtolower($this->catalogNumber($catalogNumber) ?? ''));
    }

    private function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\0", '', $value)) ?? '');
    }
}
