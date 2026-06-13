<?php

use ApiPlatform\State\Exception\ParameterNotSupportedException;

$configuration = require base_path('vendor/api-platform/laravel/config/api-platform.php');

$configuration['title'] = 'Music Library API';
$configuration['description'] = 'Local music library catalog and scanner API.';
$configuration['exception_to_status'][ParameterNotSupportedException::class] = 400;

return $configuration;
