<?php

use ApiPlatform\State\Exception\ParameterNotSupportedException;

$configuration = require base_path('vendor/api-platform/laravel/config/api-platform.php');

$configuration['title'] = 'Sonotheque API';
$configuration['description'] = 'Sonotheque local music catalog and scanner API.';
$configuration['exception_to_status'][ParameterNotSupportedException::class] = 400;

return $configuration;
