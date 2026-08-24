<?php

namespace App\Music\Ratings;

use App\Music\Metadata\Mp3Id3v2TagEditor;
use App\Music\PlaybackStatistics\UnsupportedPlaybackStatisticsTagFormat;
use RuntimeException;

final class Mp3RatingTagWriter
{
    public function __construct(
        private readonly Mp3Id3v2TagEditor $editor,
        private readonly RatingTagReader $reader,
    ) {
    }

    public function supports(string $path): bool
    {
        return $this->editor->supports($path);
    }

    public function write(string $path, ?int $trackHalfSteps, ?int $albumHalfSteps): void
    {
        if (! $this->supports($path)) {
            throw new UnsupportedPlaybackStatisticsTagFormat(
                sprintf('Rating export is not supported for .%s files.', pathinfo($path, PATHINFO_EXTENSION)),
            );
        }

        $albumValue = $albumHalfSteps === null ? '0' : $this->stars($albumHalfSteps);
        $this->editor->write(
            $path,
            [],
            [RatingTagReader::ALBUM_RATING_DESCRIPTION => $albumValue],
            function (string $temporaryPath) use ($trackHalfSteps, $albumHalfSteps): void {
                $information = (new \getID3())->analyze($temporaryPath);
                \getid3_lib::CopyTagsToComments($information);
                $written = $this->reader->read($information);
                if (! $written->trackTagPresent
                    || ! $written->albumTagPresent
                    || $written->trackHalfSteps !== $trackHalfSteps
                    || $written->albumHalfSteps !== $albumHalfSteps) {
                    throw new RuntimeException('Rating tags could not be verified after writing.');
                }
            },
            popularimeterFrames: [
                RatingTagReader::POPULARIMETER_EMAIL => $this->reader->popularimeterValue($trackHalfSteps),
            ],
        );
    }

    private function stars(int $halfSteps): string
    {
        return rtrim(rtrim(number_format($halfSteps / 2, 1, '.', ''), '0'), '.');
    }
}
