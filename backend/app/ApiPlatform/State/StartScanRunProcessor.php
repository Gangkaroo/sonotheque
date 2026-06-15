<?php

namespace App\ApiPlatform\State;

use ApiPlatform\Laravel\ApiResource\ValidationError;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Models\LibraryRoot;
use App\Models\ScanRun;
use App\Music\Scanning\ScanDispatcher;
use App\Music\Scanning\ScanDispatchException;

/** @implements ProcessorInterface<ScanRun, ScanRun> */
class StartScanRunProcessor implements ProcessorInterface
{
    public function __construct(private readonly ScanDispatcher $dispatcher) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanRun
    {
        $root = LibraryRoot::find($data->library_root_id);

        if ($root === null) {
            throw new ValidationError(
                'The requested library root does not exist.',
                hash('xxh3', 'libraryRootId'),
                violations: [[
                    'propertyPath' => 'libraryRootId',
                    'message' => 'The requested library root does not exist.',
                ]],
            );
        }

        try {
            return $this->dispatcher->dispatch($root);
        } catch (ScanDispatchException $exception) {
            throw new ValidationError(
                $exception->getMessage(),
                hash('xxh3', $exception->field),
                $exception,
                [['propertyPath' => $exception->field, 'message' => $exception->getMessage()]],
            );
        }
    }
}
