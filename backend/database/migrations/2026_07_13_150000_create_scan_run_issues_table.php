<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('scan_run_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('severity', 16);
            $table->text('message');
            $table->text('path')->nullable();
            $table->unsignedBigInteger('occurrence_count')->default(1);
            $table->timestampsTz();

            $table->index(['scan_run_id', 'severity', 'id'], 'scan_issues_run_severity_id_index');
        });

        DB::table('scan_runs')
            ->select(['id', 'summary'])
            ->orderBy('id')
            ->chunkById(100, function ($scanRuns): void {
                $rows = [];
                $timestamp = now();

                foreach ($scanRuns as $scanRun) {
                    $summary = is_string($scanRun->summary)
                        ? json_decode($scanRun->summary, true)
                        : (array) $scanRun->summary;

                    foreach ($summary['issues'] ?? [] as $issue) {
                        if (! is_array($issue)) {
                            continue;
                        }

                        $rows[] = [
                            'scan_run_id' => $scanRun->id,
                            'code' => mb_substr((string) ($issue['code'] ?? 'unknown'), 0, 64),
                            'severity' => mb_substr((string) ($issue['severity'] ?? 'warning'), 0, 16),
                            'message' => (string) ($issue['message'] ?? ''),
                            'path' => isset($issue['path']) ? (string) $issue['path'] : null,
                            'occurrence_count' => max(1, (int) ($issue['count'] ?? 1)),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('scan_run_issues')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_run_issues');
    }
};
