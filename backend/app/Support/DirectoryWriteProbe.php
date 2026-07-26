<?php

namespace App\Support;

class DirectoryWriteProbe
{
    public function canWrite(string $directory): bool
    {
        $probe = rtrim($directory, '/\\')
            .DIRECTORY_SEPARATOR
            .'.sonotheque-write-probe-'
            .bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'x+b');
        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, 'sonotheque') === 10;
        $closed = @fclose($handle);
        $removed = @unlink($probe);

        return $written && $closed && $removed;
    }
}
