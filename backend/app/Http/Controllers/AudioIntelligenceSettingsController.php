<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareAudioIntelligencePilot;
use App\Jobs\RunAudioIntelligencePilot;
use App\Models\ApplicationSetting;
use App\Models\AudioAnalysisRun;
use App\Music\Intelligence\AudioAnalysisProfileRegistry;
use App\Music\Intelligence\AudioAnalyzer;
use App\Music\Intelligence\AudioAnalyzerHealth;
use App\Music\Intelligence\AudioIntelligencePilotSampler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AudioIntelligenceSettingsController extends Controller
{
    public function __construct(
        private readonly AudioIntelligencePilotSampler $pilotSampler,
        private readonly AudioAnalyzer $analyzer,
        private readonly AudioAnalysisProfileRegistry $profileRegistry,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'sampleSize' => [
                'required',
                'integer',
                'min:'.AudioIntelligencePilotSampler::MINIMUM_SAMPLE_SIZE,
                'max:'.AudioIntelligencePilotSampler::MAXIMUM_SAMPLE_SIZE,
            ],
        ]);
        $settings = ApplicationSetting::current();
        $settings->update([
            'audio_intelligence_enabled' => $validated['enabled'],
            'audio_intelligence_sample_size' => $validated['sampleSize'],
        ]);

        return response()->json($this->payload($settings));
    }

    public function preparePilot(): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless($settings->audio_intelligence_enabled, 409, 'Enable audio intelligence before preparing a pilot.');

        $run = $this->pilotSampler->prepare($settings->audio_intelligence_sample_size);
        PrepareAudioIntelligencePilot::dispatch($run->id);

        return response()->json($this->payload($settings, $run), 202);
    }

    public function testAnalyzer(): JsonResponse
    {
        $health = $this->analyzer->health();
        Cache::put('sonotheque:audio-intelligence:analyzer-health:v2', $health->toArray(), 600);

        return response()->json($this->payload(ApplicationSetting::current(), health: $health));
    }

    public function startPilot(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless($settings->audio_intelligence_enabled, 409, 'Enable audio intelligence before running a pilot.');
        abort_unless(
            $audioAnalysisRun->phase === 'preparation'
                && $audioAnalysisRun->status === 'prepared'
                && $audioAnalysisRun->selected_track_count > 0,
            409,
            'Only a prepared, non-empty pilot can be started.',
        );

        $health = $this->analyzer->health();
        abort_unless(
            $health->ready(),
            409,
            $health->message ?? 'The audio analyzer is not ready.',
        );

        $profile = $this->profileRegistry->resolve($health->profile);
        $audioAnalysisRun->update([
            'audio_analysis_profile_id' => $profile->id,
            'phase' => 'analysis',
            'status' => 'queued',
            'finished_at' => null,
            'cancel_requested_at' => null,
            'heartbeat_at' => null,
        ]);
        $audioAnalysisRun->items()->where('status', 'selected')->update(['status' => 'queued']);
        RunAudioIntelligencePilot::dispatch($audioAnalysisRun->id);

        return response()->json(
            $this->payload($settings, $audioAnalysisRun->fresh(), $health),
            202,
        );
    }

    public function cancelPilot(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        abort_unless(
            in_array($audioAnalysisRun->status, ['fingerprinting', 'queued', 'running'], true),
            409,
            'Only an active pilot can be cancelled.',
        );

        $audioAnalysisRun->update(['cancel_requested_at' => now()]);

        return response()->json($this->payload(
            ApplicationSetting::current(),
            $audioAnalysisRun->fresh(),
        ));
    }

    public function resumePilot(AudioAnalysisRun $audioAnalysisRun): JsonResponse
    {
        $settings = ApplicationSetting::current();
        abort_unless($settings->audio_intelligence_enabled, 409, 'Enable audio intelligence before resuming a pilot.');
        abort_unless(
            $this->canResume($audioAnalysisRun),
            409,
            'This pilot is either complete or still has an active worker.',
        );

        $summary = $audioAnalysisRun->summary ?? [];
        if ($audioAnalysisRun->phase === 'preparation') {
            unset($summary['fingerprintPreparationError']);
            $audioAnalysisRun->items()
                ->whereIn('status', ['fingerprint_failed', 'cancelled', 'fingerprinting'])
                ->update([
                    'status' => 'pending_fingerprint',
                    'error' => null,
                ]);
            $status = 'fingerprinting';
        } else {
            unset($summary['analysisError']);
            $audioAnalysisRun->items()
                ->whereIn('status', ['failed', 'cancelled', 'running'])
                ->update([
                    'status' => 'queued',
                    'error' => null,
                ]);
            $status = 'queued';
        }
        $audioAnalysisRun->update([
            'status' => $status,
            'summary' => $summary,
            'finished_at' => null,
            'cancel_requested_at' => null,
            'heartbeat_at' => null,
        ]);
        if ($audioAnalysisRun->phase === 'preparation') {
            PrepareAudioIntelligencePilot::dispatch($audioAnalysisRun->id);
        } else {
            RunAudioIntelligencePilot::dispatch($audioAnalysisRun->id);
        }

        return response()->json($this->payload($settings, $audioAnalysisRun->fresh()), 202);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     sampleSize: int,
     *     eligibleTrackCount: int,
     *     fingerprintedTrackCount: int,
     *     analyzerStatus: string,
     *     analyzer: array<string, mixed>,
     *     latestPilot: ?array<string, mixed>
     * }
     */
    private function payload(
        ApplicationSetting $settings,
        ?AudioAnalysisRun $run = null,
        ?AudioAnalyzerHealth $health = null,
    ): array {
        $analyzer = $health?->toArray() ?? Cache::remember(
            'sonotheque:audio-intelligence:analyzer-health:v2',
            600,
            fn (): array => $this->analyzer->health()->toArray(),
        );
        $latestPilot = $run ?? AudioAnalysisRun::query()->latest('id')->first();
        $latestPilot?->loadMissing('profile');
        $coverage = $this->pilotSampler->coverage();

        return [
            'enabled' => $settings->audio_intelligence_enabled,
            'sampleSize' => $settings->audio_intelligence_sample_size,
            'eligibleTrackCount' => $coverage['eligibleTrackCount'],
            'fingerprintedTrackCount' => $coverage['fingerprintedTrackCount'],
            'analyzerStatus' => $analyzer['status'],
            'analyzer' => $analyzer,
            'latestPilot' => $latestPilot === null ? null : [
                'id' => $latestPilot->id,
                'phase' => $latestPilot->phase,
                'status' => $latestPilot->status,
                'requestedTrackCount' => $latestPilot->requested_track_count,
                'selectedTrackCount' => $latestPilot->selected_track_count,
                'summary' => $latestPilot->summary,
                'resumable' => $this->canResume($latestPilot),
                'profile' => $latestPilot->profile === null ? null : [
                    'analyzerName' => $latestPilot->profile->analyzer_name,
                    'analyzerVersion' => $latestPilot->profile->analyzer_version,
                    'analyzerLicense' => $latestPilot->profile->analyzer_license,
                    'modelName' => $latestPilot->profile->model_name,
                    'modelVersion' => $latestPilot->profile->model_version,
                    'modelChecksum' => $latestPilot->profile->model_checksum,
                    'modelLicense' => $latestPilot->profile->model_license,
                    'embeddingDimensions' => $latestPilot->profile->embedding_dimensions,
                ],
                'startedAt' => $latestPilot->started_at?->toAtomString(),
                'finishedAt' => $latestPilot->finished_at?->toAtomString(),
                'cancelRequestedAt' => $latestPilot->cancel_requested_at?->toAtomString(),
                'createdAt' => $latestPilot->created_at?->toAtomString(),
            ],
        ];
    }

    private function canResume(AudioAnalysisRun $run): bool
    {
        if (in_array($run->status, ['failed', 'partial', 'cancelled'], true)) {
            return true;
        }
        if (! in_array($run->status, ['fingerprinting', 'queued', 'running'], true)) {
            return false;
        }

        $lastActivity = $run->heartbeat_at ?? $run->updated_at;
        $staleMinutes = max(
            1,
            (int) config('sonotheque.audio_intelligence.resume_stale_minutes', 10),
        );

        return $lastActivity !== null && $lastActivity->lte(now()->subMinutes($staleMinutes));
    }
}
