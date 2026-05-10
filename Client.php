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

    public function __construct(array $options)
    {
        $this->apiKey      = $options['api_key'];
        $this->platformUrl = rtrim($options['platform_url'] ?? 'https://botversion.com', '/');
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

    // ── Register endpoints (batched) ─────────────────────────────────────────

    public function registerEndpoints(array $endpoints): void
    {
        if (empty($endpoints)) return;

        $this->queue = array_merge($this->queue, $endpoints);
        $this->flush();
    }

    // ── Flush batch ──────────────────────────────────────────────────────────

    public function flush(): void
    {
        if (empty($this->queue)) return;

        $toSend      = $this->queue;
        $this->queue = [];

        try {
            $data = $this->post('/api/sdk/register-endpoints', [
                'workspaceKey' => $this->apiKey,
                'endpoints'    => $toSend,
            ]);
        } catch (\Exception $e) {
        }
    }

    public function registerRoutePatterns(array $patterns): void
    {
        if (empty($patterns)) return;

        try {
            $this->post('/api/sdk/register-route-patterns', [
                'workspaceKey' => $this->apiKey,
                'patterns'     => $patterns,
            ]);
        } catch (\Exception $e) {
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
        }
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
