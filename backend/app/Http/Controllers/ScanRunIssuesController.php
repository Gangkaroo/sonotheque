<?php

namespace App\Http\Controllers;

use App\Models\ScanRun;
use App\Models\ScanRunIssue;
use Illuminate\Http\JsonResponse;

class ScanRunIssuesController extends Controller
{
    public function __invoke(ScanRun $scanRun): JsonResponse
    {
        $issues = $scanRun->issues()
            ->orderBy('id')
            ->get();

        return response()->json([
            'items' => $issues->map(static fn (ScanRunIssue $issue): array => [
                'id' => $issue->id,
                'code' => $issue->code,
                'severity' => $issue->severity,
                'message' => $issue->message,
                'path' => $issue->path,
                'count' => $issue->occurrence_count,
            ])->values(),
            'total' => $issues->count(),
            'totalOccurrences' => $issues->sum('occurrence_count'),
        ]);
    }
}
