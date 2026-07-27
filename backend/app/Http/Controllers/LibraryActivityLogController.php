<?php

namespace App\Http\Controllers;

use App\Models\LibraryActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LibraryActivityLogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'libraryRoot' => ['sometimes', 'integer', 'min:1'],
            'severity' => ['sometimes', Rule::in(['info', 'warning', 'error'])],
            'source' => ['sometimes', Rule::in(['watcher', 'scan'])],
        ]);

        $logs = LibraryActivityLog::query()
            ->with('libraryRoot:id,name')
            ->when(
                isset($validated['libraryRoot']),
                fn ($query) => $query->where('library_root_id', $validated['libraryRoot']),
            )
            ->when(
                isset($validated['severity']),
                fn ($query) => $query->where('severity', $validated['severity']),
            )
            ->when(
                isset($validated['source']),
                fn ($query) => $query->where('source', $validated['source']),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'items' => $logs->getCollection()->map(
                static fn (LibraryActivityLog $log): array => [
                    'id' => $log->id,
                    'libraryRootId' => $log->library_root_id,
                    'libraryRootName' => $log->libraryRoot?->name,
                    'scanRunId' => $log->scan_run_id,
                    'source' => $log->source,
                    'severity' => $log->severity,
                    'code' => $log->code,
                    'message' => $log->message,
                    'path' => $log->path,
                    'count' => $log->occurrence_count,
                    'context' => $log->context,
                    'createdAt' => $log->created_at?->toJSON(),
                ],
            )->values(),
            'page' => $logs->currentPage(),
            'lastPage' => $logs->lastPage(),
            'total' => $logs->total(),
        ]);
    }
}
