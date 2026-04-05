<?php

declare(strict_types=1);

date_default_timezone_set('America/New_York');

require dirname(__DIR__) . '/src/AlertBackend.php';

try {
    $app = new AlertBackend(dirname(__DIR__, 2));
    $status = $app->pollNow(true);

    $timestamp = $status['last_poll_at'] !== '' ? $status['last_poll_at'] : gmdate('Y-m-d H:i:s');
    $message = $status['last_message'] !== '' ? $status['last_message'] : 'poll completed';

    fwrite(STDOUT, sprintf("[%s] %s\n", $timestamp, $message));
    if ($status['last_error'] !== '') {
        fwrite(STDERR, sprintf("[%s] %s\n", $timestamp, $status['last_error']));
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
