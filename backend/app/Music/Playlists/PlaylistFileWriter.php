<?php

namespace App\Music\Playlists;

use App\Support\DirectoryWriteProbe;

class PlaylistFileWriter
{
    /** @var list<string> */
    public const FORMATS = ['m3u8', 'm3u'];

    public function __construct(
        private readonly DirectoryWriteProbe $directoryWriteProbe,
    ) {
    }

    public function format(string $format): string
    {
        $format = mb_strtolower(trim($format));
        if (! in_array($format, self::FORMATS, true)) {
            throw new PlaylistExportException('The playlist format must be M3U or M3U8.');
        }

        return $format;
    }

    public function filename(string $filename, string $format): string
    {
        $filename = trim($filename);
        if ($filename === ''
            || mb_strlen($filename) > 255
            || preg_match('/[\x00-\x1F<>:"\/\\\\|?*]/u', $filename) === 1
            || preg_match('/[. ]$/u', $filename) === 1) {
            throw new PlaylistExportException('The playlist filename contains unsupported characters.');
        }
        if (mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $format) {
            throw new PlaylistExportException("The playlist filename must end in .{$format}.");
        }
        if ($this->isReservedWindowsName(pathinfo($filename, PATHINFO_FILENAME))) {
            throw new PlaylistExportException('The playlist filename is reserved by Windows.');
        }

        return $filename;
    }

    public function defaultFilename(string $name, string $format): string
    {
        return $this->safeDefaultName($name).'.'.$this->format($format);
    }

    /**
     * @param list<string> $paths
     * @return array{filename: string, format: string, sizeBytes: int}
     */
    public function write(
        string $directory,
        string $format,
        string $filename,
        array $paths,
        bool $overwrite,
    ): array {
        $format = $this->format($format);
        $filename = $this->filename($filename, $format);
        if (! $this->directoryWriteProbe->canWrite($directory)) {
            throw new PlaylistExportException('The playlist destination is not writable.');
        }

        $target = $directory.DIRECTORY_SEPARATOR.$filename;
        if (is_link($target)) {
            throw new PlaylistExportException('The playlist destination must not be a symbolic link.');
        }
        if (file_exists($target) && ! is_file($target)) {
            throw new PlaylistExportException('The playlist destination is not a regular file.', 409);
        }
        if (file_exists($target) && ! $overwrite) {
            throw new PlaylistExportException('A playlist with this name already exists.', 409);
        }

        $content = $paths === [] ? '' : implode("\r\n", $paths)."\r\n";
        if ($format === 'm3u') {
            $content = "\xEF\xBB\xBF".$content;
        }
        $written = @file_put_contents($target, $content, LOCK_EX);
        if ($written === false || $written !== strlen($content)) {
            throw new PlaylistExportException('The playlist file could not be written.');
        }

        return [
            'format' => $format,
            'filename' => $filename,
            'sizeBytes' => $written,
        ];
    }

    private function safeDefaultName(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]+/u', '-', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value, " .\t\n\r\0\x0B")) ?? '';
        if ($value === '') {
            $value = 'Playlist';
        }
        if ($this->isReservedWindowsName($value)) {
            $value = '_'.$value;
        }

        return mb_substr($value, 0, 240);
    }

    private function isReservedWindowsName(string $value): bool
    {
        return preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $value) === 1;
    }
}
