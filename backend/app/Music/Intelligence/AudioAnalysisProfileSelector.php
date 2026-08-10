<?php

namespace App\Music\Intelligence;

use App\Enums\MediaFileStatus;
use App\Models\AudioAnalysisProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AudioAnalysisProfileSelector
{
    private const CACHE_KEY = 'sonotheque:audio-intelligence:current-profile:v1';

    public function current(): ?AudioAnalysisProfile
    {
        $profileId = Cache::remember(
            self::CACHE_KEY,
            60,
            fn (): ?int => $this->bestCoveredProfileId(),
        );

        return $profileId === null ? null : AudioAnalysisProfile::find($profileId);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function bestCoveredProfileId(): ?int
    {
        $coverage = DB::table('audio_analysis_run_items as profile_items')
            ->join(
                'audio_analysis_artifacts as profile_artifacts',
                'profile_artifacts.id',
                '=',
                'profile_items.audio_analysis_artifact_id',
            )
            ->join('tracks as profile_tracks', 'profile_tracks.id', '=', 'profile_items.track_id')
            ->join(
                'media_files as profile_media_files',
                'profile_media_files.id',
                '=',
                'profile_tracks.media_file_id',
            )
            ->join(
                'library_roots as profile_library_roots',
                'profile_library_roots.id',
                '=',
                'profile_media_files.library_root_id',
            )
            ->whereIn('profile_items.status', ['completed', 'reused'])
            ->where('profile_media_files.status', MediaFileStatus::Available->value)
            ->where('profile_library_roots.enabled', true)
            ->groupBy('profile_artifacts.audio_analysis_profile_id')
            ->selectRaw(
                'profile_artifacts.audio_analysis_profile_id, '
                .'COUNT(DISTINCT profile_items.track_id) AS available_track_count',
            );

        $profileId = AudioAnalysisProfile::query()
            ->joinSub($coverage, 'profile_coverage', function ($join): void {
                $join->on(
                    'profile_coverage.audio_analysis_profile_id',
                    '=',
                    'audio_analysis_profiles.id',
                );
            })
            ->orderByDesc('profile_coverage.available_track_count')
            ->orderByDesc('audio_analysis_profiles.id')
            ->value('audio_analysis_profiles.id');

        return $profileId === null ? null : (int) $profileId;
    }
}
