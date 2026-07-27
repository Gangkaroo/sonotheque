<?php

namespace App\Http\Controllers;

use App\Music\Scanning\FolderBrowser;
use App\Music\Scanning\InvalidLibraryPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderBrowserController extends Controller
{
    public function __invoke(Request $request, FolderBrowser $browser): JsonResponse
    {
        $path = $request->query('path');

        if ($path !== null && ! is_string($path)) {
            return response()->json(['message' => 'The folder path must be a string.'], 422);
        }

        try {
            return response()->json($browser->browse(
                $path,
                $request->boolean('playlistFiles'),
            ));
        } catch (InvalidLibraryPath $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
