<?php

namespace App\Http\Controllers;

use App\Models\Musician;
use App\Support\CatalogPayloads;
use App\Support\LibraryRootScope;
use App\Support\MusicianCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MusicianCatalogController extends Controller
{
    public function __construct(
        private readonly CatalogPayloads $payloads,
        private readonly LibraryRootScope $libraryRootScope,
        private readonly MusicianCatalog $musicians,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:512'],
            'initial' => ['sometimes', 'nullable', 'string', 'in:#,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z'],
        ]);
        $query = $this->musicians->query($libraryRootId)
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where('musicians.name', 'ilike', '%'.$this->escapeLike($search).'%'))
            ->when($filters['initial'] ?? null, function (Builder $query, string $initial): void {
                if ($initial === '#') {
                    $query->whereRaw("upper(left(coalesce(musicians.sort_name, musicians.name), 1)) !~ '[A-Z]'");

                    return;
                }

                $query->whereRaw(
                    'upper(left(coalesce(musicians.sort_name, musicians.name), 1)) = ?',
                    [$initial],
                );
            })
            ->orderByRaw('coalesce(musicians.sort_name, musicians.name)')
            ->orderBy('musicians.name')
            ->orderBy('musicians.id');
        $page = $query->paginate(50);

        return response()->json([
            ...$this->payloads->paginated($page, fn (Musician $musician): array => $this->payload($musician)),
            'coverage' => $this->musicians->coverage($libraryRootId),
        ]);
    }

    public function show(Request $request, Musician $musician): JsonResponse
    {
        $libraryRootId = $this->libraryRootScope->id($request);
        $musician = $this->musicians->query($libraryRootId)->findOrFail($musician->id);

        return response()->json($this->payload($musician));
    }

    /** @return array<string, mixed> */
    private function payload(Musician $musician): array
    {
        return [
            'id' => $musician->id,
            'name' => $musician->name,
            'sortName' => $musician->sort_name,
            'disambiguation' => $musician->disambiguation,
            'albumCount' => (int) $musician->album_count,
            'trackCount' => (int) $musician->track_count,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value));
    }
}
