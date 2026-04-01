<?php

declare(strict_types=1);

date_default_timezone_set('America/New_York');

require dirname(__DIR__) . '/src/AlertBackend.php';

try {
    $app = new AlertBackend(dirname(__DIR__, 2));
    $app->handle();
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>ThinkPad Alert Monitor</title>
      <style>
        body {
          margin: 0;
          min-height: 100vh;
          display: grid;
          place-items: center;
          background: #050505;
          color: #ffd7d7;
          font-family: "Trebuchet MS", "Segoe UI", sans-serif;
        }
        .panel {
          width: min(760px, calc(100vw - 2rem));
          padding: 1.5rem;
          border-radius: 22px;
          border: 1px solid rgba(255, 80, 80, 0.18);
          background: linear-gradient(180deg, rgba(22, 7, 7, 0.96), rgba(10, 10, 10, 0.98));
          box-shadow: 0 22px 50px rgba(0, 0, 0, 0.55);
        }
        h1 {
          margin: 0 0 0.6rem;
          color: #ff4040;
          text-transform: uppercase;
          letter-spacing: 0.08em;
        }
        p, pre {
          margin: 0.6rem 0 0;
          color: #ffb3b3;
        }
        pre {
          white-space: pre-wrap;
          word-break: break-word;
          font-family: Consolas, monospace;
        }
      </style>
    </head>
    <body>
      <section class="panel">
        <h1>Startup Error</h1>
        <p>The PHP backend could not start with the current configuration.</p>
        <pre><?= htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') ?></pre>
      </section>
    </body>
    </html>
    <?php
}
