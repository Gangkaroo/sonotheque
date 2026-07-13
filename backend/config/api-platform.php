<?php

use ApiPlatform\State\Exception\ParameterNotSupportedException;

$configuration = require base_path('vendor/api-platform/laravel/config/api-platform.php');

$configuration['title'] = 'Sonotheque API';
$configuration['description'] = 'Sonotheque local music catalog and scanner API.';
$configuration['resources'] = filter_var(env('API_PLATFORM_RESOURCES_ENABLED', true), FILTER_VALIDATE_BOOL)
    ? [app_path('Models')]
    : [];
$configuration['exception_to_status'][ParameterNotSupportedException::class] = 400;

return $configuration;
