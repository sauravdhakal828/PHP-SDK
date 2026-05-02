<?php
// botversion-sdk-php/Interceptor.php

class BotVersionInterceptor
{
    private $client;
    private $options;
    private static $reported = [];

    private const IGNORE_PATHS = [
        '/health',
        '/favicon.ico',
        '/_next',
        '/static',
        '/telescope',
        '/horizon',
    ];

    public function __construct($client, array $options = [])
    {
        $this->client  = $client;
        $this->options = $options;

        $client->setExecutor(function (string $method, string $path, $body, string $cookies, array $headers, string $baseUrl) {
            return $this->makeInternalRequest($method, $path, $body, $cookies, $headers, $baseUrl);
        });
    }

    // ── Laravel middleware handle method ─────────────────────────────────────

    public function handle($request, \Closure $next)
    {
        $path   = '/' . ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        if (!$this->shouldIgnore($path)) {
            $apiPrefix = $this->options['api_prefix'] ?? null;

            if (!$apiPrefix || str_starts_with($path, $apiPrefix)) {
                $normalizedPath = $this->normalizePath($path);
                $bodyStructure  = $method !== 'GET'
                    ? $this->buildBodyStructure($request->except(['_token', '_method']))
                    : null;

                $bodyKey = $method . ':' . $normalizedPath . ':'
                    . implode(',', array_keys($bodyStructure ?? []));

                if (!isset(self::$reported[$bodyKey])) {
                    self::$reported[$bodyKey] = true;

                    $jsonSchema = $this->toJsonSchema($bodyStructure);

                    $this->reportAsync($method, $normalizedPath, $jsonSchema);
                }
            }
        }

        return $next($request);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function shouldIgnore(string $path): bool
    {
        $ignorePaths = array_merge(self::IGNORE_PATHS, $this->options['exclude'] ?? []);
        foreach ($ignorePaths as $ignore) {
            if (str_starts_with($path, $ignore)) return true;
        }
        return false;
    }

    private function normalizePath(string $path): string
    {
        $segments   = explode('/', $path);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                $normalized[] = $segment;
                continue;
            }

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
                $normalized[] = ':id';
            } elseif (preg_match('/^\d+$/', $segment)) {
                $normalized[] = ':id';
            } elseif (preg_match('/^c[a-z0-9]{20,}$/i', $segment)) {
                $normalized[] = ':id';
            } elseif (preg_match('/^[0-9a-f]{24}$/i', $segment)) {
                $normalized[] = ':id';
            } elseif (strlen($segment) >= 16 && preg_match('/[a-zA-Z]/', $segment) && preg_match('/[0-9]/', $segment)) {
                $normalized[] = ':id';
            } else {
                $normalized[] = $segment;
            }
        }

        return implode('/', $normalized);
    }

    private function buildBodyStructure(array $body): ?array
    {
        if (empty($body)) return null;

        $sensitiveKeys = [
            'password', 'token', 'secret', 'apikey', 'api_key',
            'creditcard', 'credit_card', 'ssn', 'cvv', 'pin',
        ];
        $structure = [];

        foreach ($body as $key => $val) {
            $isSensitive = false;
            foreach ($sensitiveKeys as $sk) {
                if (str_contains(strtolower($key), $sk)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $structure[$key] = '[redacted]';
            } elseif (is_array($val)) {
                $structure[$key] = 'array';
            } elseif (is_null($val)) {
                $structure[$key] = 'null';
            } elseif (is_bool($val)) {
                $structure[$key] = 'boolean';
            } elseif (is_int($val) || is_float($val)) {
                $structure[$key] = 'number';
            } else {
                $structure[$key] = 'string';
            }
        }

        return $structure;
    }

    private function toJsonSchema(?array $bodyStructure): ?array
    {
        if (empty($bodyStructure)) return null;

        $properties = [];
        foreach ($bodyStructure as $key => $type) {
            $properties[$key] = [
                'type' => ($type === 'null' || $type === '[redacted]') ? 'string' : $type,
            ];
        }

        return [
            'type'       => 'object',
            'properties' => $properties,
        ];
    }

    // ── Report endpoint asynchronously ───────────────────────────────────────
    // Uses cURL with a very short timeout so it never blocks the user response

    private function reportAsync(string $method, string $path, ?array $jsonSchema): void
    {
        $payload = json_encode([
            'workspaceKey' => $this->client->getApiKey(),
            'method'       => $method,
            'path'         => $path,
            'requestBody'  => $jsonSchema,
            'detectedBy'   => 'runtime',
        ]);

        $url = $this->client->getPlatformUrl() . '/api/sdk/update-endpoint';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT_MS     => 500,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function makeInternalRequest(string $method, string $path, $body, string $cookies, array $headers, string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/') . $path;
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

        $ch = curl_init($url);
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
}
