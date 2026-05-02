<?php
// botversion-sdk-php/Client.php

class BotVersionClient
{
    const SDK_VERSION = "1.0.0";

    private $apiKey;
    private $platformUrl;
    private $debug;
    private $timeout;
    private $queue = [];
    private $executor = null;
    private $wsProcess = null;

    public function __construct(array $options)
    {
        $this->apiKey      = $options['api_key'];
        $this->platformUrl = rtrim($options['platform_url'] ?? 'http://localhost:3000', '/');
        $this->debug       = $options['debug'] ?? false;
        $this->timeout     = $options['timeout'] ?? 5;
    }

    // ── Public getters (used by Interceptor) ─────────────────────────────────

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getPlatformUrl(): string
    {
        return $this->platformUrl;
    }

    public function setExecutor(callable $executor): void
    {
        $this->executor = $executor;
    }

    // ── Register endpoints (batched) ─────────────────────────────────────────

    public function registerEndpoints(array $endpoints): void
    {
        if (empty($endpoints)) return;

        if ($this->debug) {
            error_log("[BotVersion SDK] Queuing " . count($endpoints) . " endpoints for registration");
        }

        $this->queue = array_merge($this->queue, $endpoints);
        $this->flush();
    }

    // ── Flush batch ──────────────────────────────────────────────────────────

    public function flush(): void
    {
        if (empty($this->queue)) return;

        $toSend      = $this->queue;
        $this->queue = [];

        if ($this->debug) {
            error_log("[BotVersion SDK] Flushing " . count($toSend) . " endpoints to platform");
        }

        try {
            $data = $this->post('/api/sdk/register-endpoints', [
                'workspaceKey' => $this->apiKey,
                'endpoints'    => $toSend,
            ]);

            if ($this->debug) {
                $succeeded = $data['succeeded'] ?? count($toSend);
                error_log("[BotVersion SDK] Registered {$succeeded} endpoints successfully");
            }
        } catch (\Exception $e) {
            if ($this->debug) {
                error_log("[BotVersion SDK] ⚠ Failed to register endpoints: " . $e->getMessage());
            }
        }
    }

    public function registerRoutePatterns(array $patterns): void
    {
        if (empty($patterns)) return;

        if ($this->debug) {
            error_log("[BotVersion SDK] Sending " . count($patterns) . " route patterns to platform");
        }

        try {
            $this->post('/api/sdk/register-route-patterns', [
                'workspaceKey' => $this->apiKey,
                'patterns'     => $patterns,
            ]);
        } catch (\Exception $e) {
            if ($this->debug) {
                error_log("[BotVersion SDK] ⚠ Failed to register route patterns: " . $e->getMessage());
            }
        }
    }

    // ── Update single endpoint (runtime) ─────────────────────────────────────

    public function updateEndpoint(array $endpoint): void
    {
        try {
            $this->post('/api/sdk/update-endpoint', [
                'workspaceKey' => $this->apiKey,
                'method'       => $endpoint['method'] ?? null,
                'path'         => $endpoint['path'] ?? null,
                'requestBody'  => $endpoint['requestBody'] ?? $endpoint['request_body'] ?? null,
                'detectedBy'   => $endpoint['detectedBy'] ?? $endpoint['detected_by'] ?? 'runtime',
            ]);
        } catch (\Exception $e) {
            if ($this->debug) {
                error_log("[BotVersion SDK] ⚠ Failed to update endpoint: " . $e->getMessage());
            }
        }
    }

    public function connect(): void
    {
        $wsUrl = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $this->platformUrl);
        $wsUrl = preg_replace('/:3000$/', ':3001', $wsUrl);
        $wsUrl = $wsUrl . '?apiKey=' . urlencode($this->apiKey);

        $scriptPath = __DIR__ . '/ws-worker.php';

        // Write the worker script if it doesn't exist
        $this->writeWsWorker($scriptPath);

        $cmd = 'php ' . escapeshellarg($scriptPath)
            . ' ' . escapeshellarg($wsUrl)
            . ' ' . escapeshellarg($this->platformUrl)
            . ' ' . escapeshellarg($this->apiKey)
            . ' ' . ($this->debug ? '1' : '0');

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }

        if ($this->debug) {
            error_log("[BotVersion SDK] ✅ WebSocket worker started");
        }
    }

    private function writeWsWorker(string $path): void
    {
        if (file_exists($path)) return;

    $code = <<<'PHP'
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
PHP;

        file_put_contents($path, $code);
    }

    // ── Get all endpoints ────────────────────────────────────────────────────

    public function getEndpoints(): array
    {
        return $this->get('/api/sdk/get-endpoints?workspaceKey=' . urlencode($this->apiKey));
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    private function post(string $path, array $data): array
    {
        $url  = $this->platformUrl . $path;
        $body = json_encode($data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
                'X-BotVersion-SDK: ' . self::SDK_VERSION,
            ],
        ]);

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Request failed: " . $curlError);
        }

        $parsed = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON response from platform");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMsg = $parsed['error'] ?? $response;
            throw new \RuntimeException("Platform returned {$statusCode}: {$errorMsg}");
        }

        return $parsed;
    }

    private function get(string $path): array
    {
        $url = $this->platformUrl . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'X-BotVersion-SDK: ' . self::SDK_VERSION,
            ],
        ]);

        $response   = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Request failed: " . $curlError);
        }

        $parsed = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON response from platform");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorMsg = $parsed['error'] ?? $response;
            throw new \RuntimeException("Platform returned {$statusCode}: {$errorMsg}");
        }

        return $parsed;
    }
}
