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
    }

    // ── Laravel middleware handle method ─────────────────────────────────────

    public function handle($request, \Closure $next)
    {
        $path   = '/' . ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        $response = $next($request);

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

                    if ($response->getStatusCode() < 500) {
                        $jsonSchema = $this->toJsonSchema($bodyStructure);
                        $this->reportAsync($method, $normalizedPath, $jsonSchema);
                    }
                }
            }
        }

        return $response;
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
}
