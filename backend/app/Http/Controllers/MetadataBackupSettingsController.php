<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use App\Music\Metadata\InvalidMetadataBackupPath;
use App\Music\Metadata\MetadataBackupManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MetadataBackupSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(ApplicationSetting::current()));
    }

    public function update(Request $request, MetadataBackupManager $backups): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'path' => ['required', 'string', 'max:4096', 'not_regex:/^\s*$/'],
            'retentionDays' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        try {
            $path = $backups->prepareRoot($validated['path']);
        } catch (InvalidMetadataBackupPath $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        $settings = ApplicationSetting::current();
        $settings->update([
            'metadata_backups_enabled' => $validated['enabled'],
            'metadata_backup_path' => $path,
            'metadata_backup_retention_days' => $validated['retentionDays'],
        ]);

        return response()->json($this->payload($settings));
    }

    /** @return array{enabled: bool, path: string, retentionDays: int} */
    private function payload(ApplicationSetting $settings): array
    {
        return [
            'enabled' => $settings->metadata_backups_enabled,
            'path' => $settings->metadata_backup_path
                ?? config('sonotheque.metadata_backups.default_path'),
            'retentionDays' => $settings->metadata_backup_retention_days,
        ];
    }
}
