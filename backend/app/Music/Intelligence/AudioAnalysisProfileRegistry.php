<?php

namespace App\Music\Intelligence;

use App\Models\AudioAnalysisProfile;

class AudioAnalysisProfileRegistry
{
    public function resolve(AnalyzerProfile $profile): AudioAnalysisProfile
    {
        return AudioAnalysisProfile::query()->firstOrCreate(
            [
                'profile_key' => $profile->key,
                'analyzer_version' => $profile->analyzerVersion,
                'model_version' => $profile->modelVersion,
                'model_checksum' => $profile->modelChecksum,
            ],
            [
                'protocol_version' => $profile->protocolVersion,
                'analyzer_name' => $profile->analyzerName,
                'analyzer_license' => $profile->analyzerLicense,
                'model_name' => $profile->modelName,
                'model_license' => $profile->modelLicense,
                'embedding_dimensions' => $profile->embeddingDimensions,
                'sample_rate' => $profile->sampleRate,
                'manifest' => $profile->manifest,
            ],
        );
    }
}
