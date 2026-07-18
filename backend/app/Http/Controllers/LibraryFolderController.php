<?php

namespace App\Http\Controllers;

use App\Models\LibraryRoot;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryEntryRenameException;
use App\Music\Scanning\LibraryEntryRenamer;
use App\Music\Scanning\LibraryFolderBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryFolderController extends Controller
{
    public function show(
        Request $request,
        LibraryRoot $libraryRoot,
        LibraryFolderBrowser $browser,
    ): JsonResponse {
        try {
            return response()->json($browser->browse($libraryRoot, $this->path($request)));
        } catch (InvalidLibraryPath $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function tracks(
        Request $request,
        LibraryRoot $libraryRoot,
        LibraryFolderBrowser $browser,
    ): JsonResponse {
        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'confirmationThreshold' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        try {
            return response()->json($browser->tracks(
                $libraryRoot,
                $validated['path'] ?? null,
                isset($validated['confirmationThreshold'])
                    ? (int) $validated['confirmationThreshold']
                    : null,
            ));
        } catch (InvalidLibraryPath $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function rename(
        Request $request,
        LibraryRoot $libraryRoot,
        LibraryEntryRenamer $renamer,
    ): JsonResponse {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            return response()->json($renamer->rename(
                $libraryRoot,
                $validated['path'],
                $validated['name'],
            ));
        } catch (LibraryEntryRenameException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        } catch (InvalidLibraryPath $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function path(Request $request): ?string
    {
        $validated = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
        ]);

        return $validated['path'] ?? null;
    }
}
