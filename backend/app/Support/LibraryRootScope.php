<?php

namespace App\Support;

use App\Enums\MediaFileStatus;
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

    public function albums(
        Builder $query,
        ?int $libraryRootId,
        string $column = 'library_root_id',
        bool $availableOnly = true,
    ): Builder {
        if ($libraryRootId !== null) {
            $query->where($column, $libraryRootId);
        } else {
            $query->whereHas(
                'libraryRoot',
                fn (Builder $libraryRoots) => $libraryRoots->where('enabled', true),
            );
        }

        return $query->when(
            $availableOnly,
            fn (Builder $albums) => $albums->whereHas(
                'mediaFiles',
                fn (Builder $mediaFiles) => $mediaFiles->where(
                    'status',
                    MediaFileStatus::Available->value,
                ),
            ),
        );
    }

    public function tracks(
        Builder $query,
        ?int $libraryRootId,
        bool $availableOnly = true,
    ): Builder {
        return $query->whereHas(
            'mediaFile',
            fn (Builder $mediaFiles) => $mediaFiles
                ->when(
                    $availableOnly,
                    fn (Builder $query) => $query->where(
                        'status',
                        MediaFileStatus::Available->value,
                    ),
                )
                ->whereHas(
                    'libraryRoot',
                    fn (Builder $libraryRoots) => $libraryRoots
                        ->where('enabled', true)
                        ->when($libraryRootId, fn (Builder $query, int $id) => $query->whereKey($id)),
                ),
        );
    }
}
