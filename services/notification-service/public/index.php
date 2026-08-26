<?php

use App\Kernel;
use App\Otel\Tracing;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

Tracing::init(
    $_SERVER['OTEL_SERVICE_NAME'] ?? $_ENV['OTEL_SERVICE_NAME'] ?? 'notification-service',
    $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? 'http://otel-collector:4318'
);

$_SERVER['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ('prod' !== $_SERVER['APP_ENV']);

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
