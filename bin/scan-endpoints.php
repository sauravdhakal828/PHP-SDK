#!/usr/bin/env php
<?php
// botversion-sdk-php/bin/scan-endpoints.php
//
// Build-time backend endpoint scanner. Run this as part of your deploy
// pipeline (Dockerfile RUN step, CI build step, or a Composer script) so
// endpoints are reported even when the live interceptor's scan-trigger
// route may not be reachable (serverless-style deploys, ephemeral
// containers, redeploys with no persistent process to "boot" into).

require_once __DIR__ . '/../Client.php';
require_once __DIR__ . '/../Scanner.php';

function botversion_load_env_files(string $cwd): void
{
    foreach (['.env', '.env.local'] as $filename) {
        $filepath = $cwd . '/' . $filename;
        if (!file_exists($filepath)) continue;

        $lines = @file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) continue;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            // Strip matching surrounding quotes, if present
            if (strlen($value) >= 2 && (
                ($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'")
            )) {
                $value = substr($value, 1, -1);
            }

            // Never overwrite a variable already set in the real environment —
            // same precedence rule the JS and Python SDKs follow.
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

function botversion_main(): void
{
    $cwd = getcwd();
    botversion_load_env_files($cwd);

    $apiKey      = getenv('BOTVERSION_API_KEY') ?: null;
    $platformUrl = getenv('BOTVERSION_PLATFORM_URL') ?: 'https://botversion.com';

    if (!$apiKey) {
        echo "[botversion] BOTVERSION_API_KEY environment variable is not set. Skipping backend endpoint scan.\n";
        exit(0);
    }

    $framework = BotVersionScanner::detectFrameworkFromComposer($cwd);
    if (!$framework) {
        echo "[botversion] No supported backend framework detected. Skipping backend endpoint scan.\n";
        exit(0);
    }

    $endpoints = BotVersionScanner::scanRoutesStatic($cwd, $framework);

    if (empty($endpoints)) {
        echo "[botversion] No backend endpoints found via static scan. detected_framework={$framework}\n";
        exit(0);
    }

    $client = new BotVersionClient([
        'api_key'      => $apiKey,
        'platform_url' => $platformUrl,
    ]);

    try {
        $client->registerEndpoints($endpoints);
        $count = count($endpoints);
        echo "[botversion] Reported {$count} backend endpoints. detected_framework={$framework}\n";
    } catch (\Exception $e) {
        echo "[botversion] Failed to report backend endpoints: " . $e->getMessage() . "\n";
    }

    exit(0);
}

botversion_main();