<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audio_similarity_feedback', function (Blueprint $table): void {
            $table->string('configuration', 32)->default('all')->after('candidate_track_id');
            $table->dropUnique('audio_similarity_feedback_identity_unique');
            $table->unique(
                [
                    'audio_analysis_profile_id',
                    'source_track_id',
                    'candidate_track_id',
                    'configuration',
                ],
                'audio_similarity_feedback_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('audio_similarity_feedback', function (Blueprint $table): void {
            $table->dropUnique('audio_similarity_feedback_identity_unique');
            $table->dropColumn('configuration');
            $table->unique(
                ['audio_analysis_profile_id', 'source_track_id', 'candidate_track_id'],
                'audio_similarity_feedback_identity_unique',
            );
        });
    }
};
