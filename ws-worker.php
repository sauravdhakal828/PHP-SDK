<?php
// ws-worker.php — runs as a background process

require_once __DIR__ . '/vendor/autoload.php';

$wsUrl       = $argv[1];
$platformUrl = $argv[2];
$apiKey      = $argv[3];
$debug       = isset($argv[4]) && $argv[4] === '1';

function makeInternalRequest(string $method, string $path, $body, string $cookies, array $headers, string $baseUrl): array
{
    $url = rtrim($baseUrl, '/') . $path;

    $ch = curl_init($url);
    $bodyJson = $body ? json_encode($body) : null;

    $curlHeaders = ['Content-Type: application/json'];

    if ($cookies) {
        $curlHeaders[] = 'Cookie: ' . $cookies;
    }

    $auth = $headers['authorization'] ?? $headers['Authorization'] ?? null;
    if ($auth) {
        $curlHeaders[] = 'Authorization: ' . $auth;
    }

    $csrf = $headers['x-csrftoken'] ?? $headers['X-CSRFToken'] ?? $headers['x-xsrf-token'] ?? $headers['X-XSRF-TOKEN'] ?? null;
    if ($csrf) {
        $curlHeaders[] = 'X-CSRFToken: ' . $csrf;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($bodyJson && strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
    }

    $response   = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error      = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status' => 500, 'ok' => false, 'data' => ['error' => $error]];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = ['raw' => $response];
    }

    return [
        'status' => $statusCode,
        'ok'     => $statusCode >= 200 && $statusCode < 300,
        'data'   => $data,
    ];
}

function connectAndListen(string $wsUrl, string $platformUrl, string $apiKey, bool $debug): void
{
    while (true) {
        try {
            $client = new \WebSocket\Client($wsUrl, ['timeout' => 30]);

            // Send IDENTIFY
            $client->send(json_encode([
                'type'   => 'IDENTIFY',
                'apiKey' => $apiKey,
            ]));

            if ($debug) error_log("[BotVersion WS] ✅ Connected and identified");

            while (true) {
                try {
                    $message = $client->receive();
                    $data    = json_decode($message, true);

                    if (!$data || $data['type'] !== 'EXECUTE_CALL') continue;

                    $callId  = $data['callId'];
                    $method  = $data['method'];
                    $path    = $data['path'];
                    $body    = $data['body'] ?? null;
                    $cookies = $data['cookies'] ?? '';
                    $headers = $data['headers'] ?? [];
                    $baseUrl = $data['baseUrl'] ?? 'http://127.0.0.1:8000';

                    $result = makeInternalRequest($method, $path, $body, $cookies, $headers, $baseUrl);

                    $client->send(json_encode([
                        'type'   => 'CALL_RESULT',
                        'callId' => $callId,
                        'result' => $result,
                    ]));

                    if ($debug) error_log("[BotVersion WS] ✅ Executed call: {$method} {$path} → {$result['status']}");

                } catch (\WebSocket\ConnectionException $e) {
                    if ($debug) error_log("[BotVersion WS] ⚠ Connection lost: " . $e->getMessage());
                    break;
                }
            }

            $client->close();

        } catch (\Exception $e) {
            if ($debug) error_log("[BotVersion WS] ⚠ Error: " . $e->getMessage());
        }

        if ($debug) error_log("[BotVersion WS] Reconnecting in 5 seconds...");
        sleep(5);
    }
}

connectAndListen($wsUrl, $platformUrl, $apiKey, $debug);