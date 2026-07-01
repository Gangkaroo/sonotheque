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
    ) {}

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

        $root->updateOrFail([
            'name' => trim($data->name),
            'cover_image_paths' => $coverImagePaths,
            'excluded_directories' => $excludedDirectories ?: null,
        ]);

        return $root->refresh();
    }
}
