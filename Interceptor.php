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
        '/_ignition',
        '/sanctum',
        '/_debugbar',
        '/profiler',
    ];

    public function __construct($client, array $options = [])
    {
        $this->client  = $client;
        $this->options = $options;
    }

    // =========================================================================
    // ── LARAVEL — PSR middleware handle() ─────────────────────────────────────
    // =========================================================================

    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        $path   = '/' . ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        if (!$this->shouldIgnore($path)) {
            $apiPrefix = $this->options['api_prefix'] ?? null;

            if (!$apiPrefix || str_starts_with($path, $apiPrefix)) {
                $normalizedPath = $this->normalizePath($path);
                $bodyStructure  = $method !== 'GET'
                    ? $this->buildBodyStructure($request->except(['_token', '_method']))
                    : null;

                $this->maybeReport($method, $normalizedPath, $bodyStructure, $response->getStatusCode());
            }
        }

        return $response;
    }

    // =========================================================================
    // ── SYMFONY — kernel.response event listener ──────────────────────────────
    // =========================================================================

    // Called by BotVersion::bootSymfony() via:
    //   $dispatcher->addListener('kernel.response', [$interceptor, 'onKernelResponse'])
    //
    // Symfony passes a ResponseEvent object which holds both the request and response.

    public function onKernelResponse($event): void
    {
        try {
            // Works with Symfony 5, 6, and 7
            // ResponseEvent is in Symfony\Component\HttpKernel\Event\ResponseEvent
            if (!method_exists($event, 'getRequest') || !method_exists($event, 'getResponse')) {
                return;
            }

            // Only process the main request, not sub-requests
            if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
                return;
            }

            $request  = $event->getRequest();
            $response = $event->getResponse();

            $path   = $request->getPathInfo();
            $method = strtoupper($request->getMethod());

            if ($this->shouldIgnore($path)) return;

            $apiPrefix = $this->options['api_prefix'] ?? null;
            if ($apiPrefix && !str_starts_with($path, $apiPrefix)) return;

            $normalizedPath = $this->normalizePath($path);

            // Symfony request body — try JSON first, then form data
            $bodyStructure = null;
            if ($method !== 'GET') {
                $contentType = $request->headers->get('Content-Type', '');

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode($request->getContent(), true);
                    $body    = is_array($decoded) ? $decoded : [];
                } else {
                    $body = $request->request->all();
                }

                $bodyStructure = $this->buildBodyStructure($body);
            }

            $this->maybeReport($method, $normalizedPath, $bodyStructure, $response->getStatusCode());

        } catch (\Exception $e) {
            // Silent — never break the app
        }
    }

    // =========================================================================
    // ── SLIM 4 — PSR-15 middleware ────────────────────────────────────────────
    // =========================================================================

    // Called by BotVersion::bootSlim() via:
    //   $slimApp->add([$interceptor, 'processSlim'])
    //
    // Slim 4 uses PSR-7 ServerRequest and PSR-15 RequestHandlerInterface.

    public function processSlim($request, $handler)
    {
        // Let the app handle the request first
        $response = $handler->handle($request);

        try {
            $path   = $request->getUri()->getPath();
            $method = strtoupper($request->getMethod());

            if ($this->shouldIgnore($path)) return $response;

            $apiPrefix = $this->options['api_prefix'] ?? null;
            if ($apiPrefix && !str_starts_with($path, $apiPrefix)) return $response;

            $normalizedPath = $this->normalizePath($path);

            // Slim PSR-7 request body
            $bodyStructure = null;
            if ($method !== 'GET') {
                $contentType = $request->getHeaderLine('Content-Type');

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode((string) $request->getBody(), true);
                    $body    = is_array($decoded) ? $decoded : [];
                } else {
                    $parsed = $request->getParsedBody();
                    $body   = is_array($parsed) ? $parsed : [];
                }

                $bodyStructure = $this->buildBodyStructure($body);
            }

            $this->maybeReport($method, $normalizedPath, $bodyStructure, $response->getStatusCode());

        } catch (\Exception $e) {
            // Silent
        }

        return $response;
    }

    // =========================================================================
    // ── CODEIGNITER 4 — post_controller event ────────────────────────────────
    // =========================================================================

    // Called by BotVersion::bootCodeIgniter() via:
    //   Events::on('post_controller', function() use ($interceptor) {
    //       $interceptor->handleCodeIgniter();
    //   })
    //
    // At this point CI4's Services::request() and Services::response() are available.

    public function handleCodeIgniter(): void
    {
        try {
            if (!class_exists('\CodeIgniter\Config\Services')) return;

            $request  = \CodeIgniter\Config\Services::request();
            $response = \CodeIgniter\Config\Services::response();

            if (!$request || !$response) return;

            $path   = '/' . ltrim($request->getPath(), '/');
            $method = strtoupper($request->getMethod());

            if ($this->shouldIgnore($path)) return;

            $apiPrefix = $this->options['api_prefix'] ?? null;
            if ($apiPrefix && !str_starts_with($path, $apiPrefix)) return;

            $normalizedPath = $this->normalizePath($path);

            // CI4 request body
            $bodyStructure = null;
            if ($method !== 'GET') {
                $contentType = $request->getHeaderLine('Content-Type');

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode((string) $request->getBody(), true);
                    $body    = is_array($decoded) ? $decoded : [];
                } else {
                    $body = $request->getPost() ?? [];
                }

                $bodyStructure = $this->buildBodyStructure($body);
            }

            $statusCode = $response->getStatusCode();
            $this->maybeReport($method, $normalizedPath, $bodyStructure, $statusCode);

        } catch (\Exception $e) {
            // Silent
        }
    }

    // =========================================================================
    // ── PLAIN PHP FALLBACK — shutdown hook ────────────────────────────────────
    // =========================================================================

    // Called by BotVersion::bootFallback() via register_shutdown_function.
    // Reads path and method from $_SERVER, body from php://input.

    public function handlePlainPhp(): void
    {
        try {
            $path   = '/' . ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

            if ($this->shouldIgnore($path)) return;

            $apiPrefix = $this->options['api_prefix'] ?? null;
            if ($apiPrefix && !str_starts_with($path, $apiPrefix)) return;

            $normalizedPath = $this->normalizePath($path);

            $bodyStructure = null;
            if ($method !== 'GET') {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode(file_get_contents('php://input'), true);
                    $body    = is_array($decoded) ? $decoded : [];
                } else {
                    $body = $_POST ?? [];
                }

                $bodyStructure = $this->buildBodyStructure($body);
            }

            // Plain PHP has no response object at shutdown — we report regardless
            // of status since we can't check it. http_response_code() gives us the
            // code that was set via header() or http_response_code().
            $statusCode = http_response_code() ?: 200;
            $this->maybeReport($method, $normalizedPath, $bodyStructure, $statusCode);

        } catch (\Exception $e) {
            // Silent
        }
    }

    // =========================================================================
    // ── SHARED HELPERS ────────────────────────────────────────────────────────
    // =========================================================================

    // Deduplicates reports — same method + path + body shape is only reported once
    // per process lifetime. This prevents hammering the platform on every request.

    private function maybeReport(string $method, string $path, ?array $bodyStructure, int $statusCode): void
    {
        // Skip server errors — endpoint may not be properly set up
        if ($statusCode >= 500) return;

        $bodyKey = implode(',', array_keys($bodyStructure ?? []));
        $key     = $method . ':' . $path . ':' . $bodyKey;

        if (isset(self::$reported[$key])) return;
        self::$reported[$key] = true;

        $jsonSchema = $this->toJsonSchema($bodyStructure);
        $this->reportAsync($method, $path, $jsonSchema);
    }

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

            // UUID
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
                $normalized[] = ':id';
            // Pure integer
            } elseif (preg_match('/^\d+$/', $segment)) {
                $normalized[] = ':id';
            // CUID — cXXXXXXXXXXXXXXXXXXXXX
            } elseif (preg_match('/^c[a-z0-9]{20,}$/i', $segment)) {
                $normalized[] = ':id';
            // MongoDB ObjectId — 24 hex chars
            } elseif (preg_match('/^[0-9a-f]{24}$/i', $segment)) {
                $normalized[] = ':id';
            // Generic alphanumeric token — 16+ chars with mixed letters+digits
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
                if (str_contains(strtolower((string) $key), $sk)) {
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

        return $structure ?: null;
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

        return ['type' => 'object', 'properties' => $properties];
    }

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
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}