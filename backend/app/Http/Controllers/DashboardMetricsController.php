<?php

namespace App\Http\Controllers;

use App\Support\CollectionMetrics;
use App\Support\LibraryRootScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardMetricsController extends Controller
{
    public function __construct(
        private readonly LibraryRootScope $libraryRootScope,
        private readonly CollectionMetrics $metrics,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);

        return response()->json($this->metrics->forLibraryRoot($libraryRootId));
    }
}
