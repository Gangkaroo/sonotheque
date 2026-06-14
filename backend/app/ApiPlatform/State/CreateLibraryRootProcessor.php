<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Laravel\ApiResource\ValidationError;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Models\Library;
use App\Models\LibraryRoot;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;

/** @implements ProcessorInterface<LibraryRoot, LibraryRoot> */
class CreateLibraryRootProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LibraryRoot
    {
        try {
            $path = $this->pathGuard->canonicalizeDirectory($data->path);
            $coverImagePath = $this->pathGuard->normalizeRelativePath($data->cover_image_path ?: 'cover.jpg');
        } catch (InvalidLibraryPath $exception) {
            $field = str_contains($exception->getMessage(), 'relative')
                || str_contains($exception->getMessage(), 'unsafe')
                ? 'coverImagePath'
                : 'path';

            $this->invalid($field, $exception->getMessage(), $exception);
        }

        $pathHash = hash('sha256', mb_strtolower($path));

        if (LibraryRoot::where('path_hash', $pathHash)->exists()) {
            $this->invalid('path', 'This folder is already configured as a library root.');
        }

        $library = Library::firstOrCreate(
            ['name' => 'Default Library'],
            ['description' => 'Local music collection'],
        );

        $data->forceFill([
            'library_id' => $library->id,
            'path' => $path,
            'path_hash' => $pathHash,
            'cover_image_path' => $coverImagePath,
            'enabled' => true,
        ]);
        $data->saveOrFail();

        return $data->refresh();
    }

    private function invalid(string $field, string $message, ?\Throwable $previous = null): never
    {
        throw new ValidationError(
            $message,
            hash('xxh3', $field),
            $previous,
            [['propertyPath' => $field, 'message' => $message]],
        );
    }
}
