<?php

namespace App\Http\Controllers;

use App\Jobs\CreateSystemBackup;
use App\Jobs\RestoreSystemBackup;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use App\Support\DirectoryWriteProbe;
use App\System\Backups\SystemBackupManager;
use App\System\Backups\SystemBackupOperationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SystemBackupController extends Controller
{
    public function __construct(
        private readonly SystemBackupManager $backups,
        private readonly SystemBackupOperationStore $operations,
        private readonly DirectoryWriteProbe $directoryWriteProbe,
    ) {
    }

    public function store(Request $request, LibraryPathGuard $pathGuard): JsonResponse
    {
        $validated = $request->validate([
            'destination' => ['required', 'string', 'max:4096'],
        ]);
        try {
            $destination = $pathGuard->canonicalizeDirectory($validated['destination']);
        } catch (InvalidLibraryPath $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if (! $this->directoryWriteProbe->canWrite($destination)) {
            return response()->json([
                'message' => 'Sonotheque cannot write to the selected backup folder.',
            ], 422);
        }

        $operation = $this->operations->create('backup');
        CreateSystemBackup::dispatch($operation['id'], $destination);

        return response()->json($this->operationPayload($operation), 202);
    }

    public function inspect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
        ]);

        try {
            return response()->json($this->backups->inspect($validated['path']));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'confirmed' => ['accepted'],
        ]);

        try {
            $archive = $this->backups->inspect($validated['path']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if (! $archive['appKeyMatches']) {
            return response()->json([
                'message' => 'This backup uses a different application encryption key and requires the command-line restore.',
            ], 422);
        }
        if (! $archive['modeMatches']) {
            return response()->json([
                'message' => 'This backup was created in a different runtime mode. Use the documented command-line migration workflow instead.',
            ], 422);
        }

        $operation = $this->operations->create('restore', [
            'archiveName' => $archive['name'],
            'archivePath' => $archive['path'],
        ]);
        RestoreSystemBackup::dispatch($operation['id'], $archive['path']);

        return response()->json($this->operationPayload($operation), 202);
    }

    public function show(string $operationId): JsonResponse
    {
        try {
            return response()->json($this->operationPayload(
                $this->operations->find($operationId),
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }

    /** @param array<string, mixed> $operation */
    private function operationPayload(array $operation): array
    {
        unset($operation['archivePath']);

        return $operation;
    }
}
