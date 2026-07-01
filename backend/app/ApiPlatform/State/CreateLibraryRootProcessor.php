<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;
use App\Music\Scanning\LibraryRootConfiguration;
use App\Music\Scanning\LibraryRootPathValidator;

/** @implements ProcessorInterface<LibraryRoot, LibraryRoot> */
class CreateLibraryRootProcessor implements ProcessorInterface
{
    use ValidatesApiInput;

    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
        private readonly LibraryRootPathValidator $rootPathValidator,
        private readonly LibraryRootConfiguration $configuration,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LibraryRoot
    {
        $path = $this->validated(
            'path',
            fn (): string => $this->pathGuard->canonicalizeDirectory($data->path),
        );
        $coverImagePaths = $this->validated(
            'coverImagePaths',
            fn (): array => $this->configuration->coverPaths($data->cover_image_paths),
        );
        $excludedDirectories = $this->validated(
            'excludedDirectories',
            fn (): array => $this->configuration->excludedDirectories($data->excluded_directories),
        );

        try {
            $this->rootPathValidator->assertAvailable($path);
        } catch (InvalidLibraryPath $exception) {
            $this->invalid('path', $exception->getMessage(), $exception);
        }

        $pathHash = hash('sha256', mb_strtolower($path));

        $library = Library::firstOrCreate(
            ['name' => 'Default Library'],
            ['description' => 'Local music collection'],
        );

        $data->forceFill([
            'library_id' => $library->id,
            'path' => $path,
            'path_hash' => $pathHash,
            'cover_image_paths' => $coverImagePaths,
            'excluded_directories' => $excludedDirectories ?: null,
            'enabled' => true,
        ]);
        $data->saveOrFail();

        return $data->refresh();
    }
}
