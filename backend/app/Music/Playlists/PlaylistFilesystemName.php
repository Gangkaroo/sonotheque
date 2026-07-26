<?php

namespace App\Music\Playlists;

class PlaylistFilesystemName
{
    public function component(string $value, string $fallback): string
    {
        $value = strtr($value, [
            '<' => '＜',
            '>' => '＞',
            ':' => '：',
            '"' => '＂',
            '/' => '／',
            '\\' => '＼',
            '|' => '｜',
            '?' => '？',
            '*' => '＊',
        ]);
        $value = preg_replace('/[\x00-\x1F]/u', '-', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value, " .\t\n\r\0\x0B")) ?? '';
        if ($value === '') {
            $value = $fallback;
        }
        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $value) === 1) {
            $value = '_'.$value;
        }

        return mb_substr($value, 0, 240);
    }
}
