<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Models\LibraryRoot;
use App\Music\Scanning\LibraryRootConfiguration;

/** @implements ProcessorInterface<LibraryRoot, LibraryRoot> */
class UpdateLibraryRootProcessor implements ProcessorInterface
{
    use ValidatesApiInput;

    public function __construct(
        private readonly LibraryRootConfiguration $configuration,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LibraryRoot
    {
        $root = LibraryRoot::findOrFail($data->id);

        if ($data->path !== $root->path) {
            $this->invalid('path', 'The folder path cannot be changed after a library root has been created.');
        }

        $requestedCoverPaths = $data->cover_image_paths ?? $root->cover_image_paths;
        $coverImagePaths = $this->validated(
            'coverImagePaths',
            fn (): array => $this->configuration->coverPaths($requestedCoverPaths),
        );
        $excludedDirectories = $this->validated(
            'excludedDirectories',
            fn (): array => $this->configuration->excludedDirectories(
                $data->excluded_directories ?? $root->excluded_directories,
            ),
        );

        $watchWasEnabled = $root->watch_enabled;
        $watchConfigurationChanged = $root->cover_image_paths !== $coverImagePaths
            || ($root->excluded_directories ?? []) !== $excludedDirectories;

        $root->updateOrFail([
            'name' => trim($data->name),
            'cover_image_paths' => $coverImagePaths,
            'excluded_directories' => $excludedDirectories ?: null,
            'watch_enabled' => (bool) ($data->watch_enabled ?? $root->watch_enabled),
            'watch_poll_interval_minutes' => (int) (
                $data->watch_poll_interval_minutes ?? $root->watch_poll_interval_minutes
            ),
            'watch_reconcile_interval_minutes' => (int) (
                $data->watch_reconcile_interval_minutes ?? $root->watch_reconcile_interval_minutes
            ),
        ]);

        if (! $root->watch_enabled) {
            $root->watchDirectories()->delete();
            $root->update([
                'watch_status' => 'disabled',
                'watch_checked_at' => null,
                'watch_last_path' => null,
                'watch_error' => null,
            ]);
        } elseif (! $watchWasEnabled || $watchConfigurationChanged) {
            $root->watchDirectories()->delete();
            $root->update([
                'watch_status' => 'pending',
                'watch_checked_at' => null,
                'watch_error' => null,
            ]);
        }

        return $root->refresh();
    }
}
