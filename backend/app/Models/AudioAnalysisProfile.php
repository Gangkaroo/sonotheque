<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_key',
    'protocol_version',
    'analyzer_name',
    'analyzer_version',
    'analyzer_license',
    'model_name',
    'model_version',
    'model_checksum',
    'model_license',
    'embedding_dimensions',
    'sample_rate',
    'manifest',
])]
class AudioAnalysisProfile extends Model
{
    /** @return HasMany<AudioAnalysisRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AudioAnalysisRun::class);
    }

    /** @return HasMany<AudioAnalysisArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(AudioAnalysisArtifact::class);
    }

    protected function casts(): array
    {
        return [
            'protocol_version' => 'integer',
            'embedding_dimensions' => 'integer',
            'sample_rate' => 'integer',
            'manifest' => 'array',
        ];
    }
}
