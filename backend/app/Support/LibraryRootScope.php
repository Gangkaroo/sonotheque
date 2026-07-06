<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LibraryRootScope
{
    public function id(Request $request): ?int
    {
        if (! $request->query->has('libraryRoot')) {
            return null;
        }

        $validated = Validator::make(
            ['libraryRoot' => $request->query('libraryRoot')],
            [
                'libraryRoot' => [
                    'nullable',
                    'integer',
                    Rule::exists('library_roots', 'id')->where('enabled', true),
                ],
            ],
        )->validate();

        return isset($validated['libraryRoot']) ? (int) $validated['libraryRoot'] : null;
    }

    public function albums(Builder $query, ?int $libraryRootId, string $column = 'library_root_id'): Builder
    {
        if ($libraryRootId !== null) {
            return $query->where($column, $libraryRootId);
        }

        return $query->whereHas(
            'libraryRoot',
            fn (Builder $libraryRoots) => $libraryRoots->where('enabled', true),
        );
    }

    public function tracks(Builder $query, ?int $libraryRootId): Builder
    {
        return $query->whereHas(
            'mediaFile.libraryRoot',
            fn (Builder $libraryRoots) => $libraryRoots
                ->where('enabled', true)
                ->when($libraryRootId, fn (Builder $query, int $id) => $query->whereKey($id)),
        );
    }
}
