<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Laravel\ApiResource\ValidationError;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Models\LibraryRoot;
use App\Music\Scanning\InvalidLibraryPath;
use App\Music\Scanning\LibraryPathGuard;

/** @implements ProcessorInterface<LibraryRoot, LibraryRoot> */
class UpdateLibraryRootProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LibraryPathGuard $pathGuard,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LibraryRoot
    {
        $root = LibraryRoot::findOrFail($data->id);

        if ($data->path !== $root->path) {
            $this->invalid('path', 'The folder path cannot be changed after a library root has been created.');
        }

        try {
            $coverImagePath = $this->pathGuard->normalizeRelativePath($data->cover_image_path ?: 'cover.jpg');
        } catch (InvalidLibraryPath $exception) {
            $this->invalid('coverImagePath', $exception->getMessage(), $exception);
        }

        $root->updateOrFail([
            'name' => trim($data->name),
            'cover_image_path' => $coverImagePath,
        ]);

        return $root->refresh();
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
