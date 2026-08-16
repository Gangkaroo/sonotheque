<?php

namespace App\Http\Controllers;

use App\Models\LibraryRoot;
use App\Models\MusicianCreditBackfillRun;
use App\Music\Enrichment\MusicianCreditBackfillManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MusicianCreditBackfillController extends Controller
{
    public function __construct(private readonly MusicianCreditBackfillManager $backfills)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->backfills->payload($this->libraryRootId($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootId($request);
        $run = $this->backfills->start(
            $libraryRootId === null ? null : LibraryRoot::query()->findOrFail($libraryRootId),
        );

        return response()->json(
            $this->backfills->payload($run->library_root_id),
            $run->status === 'queued' ? 202 : 200,
        );
    }

    public function pause(MusicianCreditBackfillRun $musicianCreditBackfillRun): JsonResponse
    {
        $run = $this->backfills->pause($musicianCreditBackfillRun);

        return response()->json($this->backfills->payload($run->library_root_id));
    }

    public function resume(MusicianCreditBackfillRun $musicianCreditBackfillRun): JsonResponse
    {
        $run = $this->backfills->resume($musicianCreditBackfillRun);

        return response()->json($this->backfills->payload($run->library_root_id), 202);
    }

    public function destroy(MusicianCreditBackfillRun $musicianCreditBackfillRun): JsonResponse
    {
        $run = $this->backfills->cancel($musicianCreditBackfillRun);

        return response()->json($this->backfills->payload($run->library_root_id));
    }

    private function libraryRootId(Request $request): ?int
    {
        $validated = $request->validate([
            'libraryRoot' => [
                'nullable',
                'integer',
                Rule::exists('library_roots', 'id')->where('enabled', true),
            ],
        ]);

        return isset($validated['libraryRoot']) ? (int) $validated['libraryRoot'] : null;
    }
}
