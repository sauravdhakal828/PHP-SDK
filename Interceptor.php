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
        $path   = '/' . ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        // ── Scan trigger from BotVersion dashboard ────────────────────────
        if ($path === '/__botversion/scan' && $method === 'POST') {
            $providedKey = $request->header('x-botversion-scan-key', '');
            $result      = $this->tryHandleScanTrigger($providedKey);

            return response()->json(array_filter([
                'success' => $result['success'],
                'result'  => $result['result'] ?? null,
                'error'   => $result['error'] ?? null,
            ], fn($v) => $v !== null), $result['status']);
        }

        $response = $next($request);

        if (!$this->shouldIgnore($path)) {
            $apiPrefix = $this->options['api_prefix'] ?? null;

            if (!$apiPrefix || str_starts_with($path, $apiPrefix)) {
                $rawBody = $method !== 'GET'
                    ? $request->except(['_token', '_method'])
                    : null;

                $this->reportEndpoint($method, $path, $rawBody, $request->getQueryString() ?? '', $response->getStatusCode());
            }
        }

        return $response;
    }

    // =========================================================================
    // ── SYMFONY — kernel.request event listener (scan trigger only) ───────────
    // =========================================================================

    // Called by BotVersion::bootSymfony() via:
    //   $dispatcher->addListener('kernel.request', [$interceptor, 'onKernelRequest'])
    //
    // Fires before routing, so it's the only place we can safely answer the
    // /__botversion/scan trigger — by the time kernel.response fires, Symfony
    // has already tried (and likely failed) to route that path.

    public function onKernelRequest($event): void
    {
        try {
            if (!method_exists($event, 'getRequest') || !method_exists($event, 'setResponse')) {
                return;
            }
            if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
                return;
            }

            $request = $event->getRequest();
            $path    = $request->getPathInfo();
            $method  = strtoupper($request->getMethod());

            if ($path !== '/__botversion/scan' || $method !== 'POST') return;

            $providedKey = $request->headers->get('x-botversion-scan-key', '');
            $result      = $this->tryHandleScanTrigger($providedKey);

            $response = new \Symfony\Component\HttpFoundation\JsonResponse(array_filter([
                'success' => $result['success'],
                'result'  => $result['result'] ?? null,
                'error'   => $result['error'] ?? null,
            ], fn($v) => $v !== null), $result['status']);

            $event->setResponse($response);

        } catch (\Exception $e) {
            // Silent — never break the app
        }
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

            $rawBody = null;
            if ($method !== 'GET') {
                $contentType = $request->headers->get('Content-Type', '');

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode($request->getContent(), true);
                    $rawBody = is_array($decoded) ? $decoded : [];
                } else {
                    $rawBody = $request->request->all();
                }
            }

            $this->reportEndpoint($method, $path, $rawBody, $request->getQueryString() ?? '', $response->getStatusCode());

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
        $path   = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // ── Scan trigger from BotVersion dashboard ────────────────────────
        if ($path === '/__botversion/scan' && $method === 'POST') {
            $providedKey = $request->getHeaderLine('x-botversion-scan-key');
            $result      = $this->tryHandleScanTrigger($providedKey);

            $factory  = new \Slim\Psr7\Factory\ResponseFactory();
            $response = $factory->createResponse($result['status']);
            $response->getBody()->write(json_encode(array_filter([
                'success' => $result['success'],
                'result'  => $result['result'] ?? null,
                'error'   => $result['error'] ?? null,
            ], fn($v) => $v !== null)));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Let the app handle the request first
        $response = $handler->handle($request);

        try {
            if ($this->shouldIgnore($path)) return $response;

            $apiPrefix = $this->options['api_prefix'] ?? null;
            if ($apiPrefix && !str_starts_with($path, $apiPrefix)) return $response;

            $rawBody = null;
            if ($method !== 'GET') {
                $contentType = $request->getHeaderLine('Content-Type');

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode((string) $request->getBody(), true);
                    $rawBody = is_array($decoded) ? $decoded : [];
                } else {
                    $parsed = $request->getParsedBody();
                    $rawBody = is_array($parsed) ? $parsed : [];
                }
            }

            $queryString = $request->getUri()->getQuery();
            $this->reportEndpoint($method, $path, $rawBody, $queryString, $response->getStatusCode());

        } catch (\Exception $e) {
            // Silent
        }

        return $response;
    }

    // =========================================================================
    // ── CODEIGNITER 4 — pre_system event (scan trigger only) ──────────────────
    // =========================================================================

    // Called by BotVersion::bootCodeIgniter() via:
    //   Events::on('pre_system', function() use ($interceptor) {
    //       $interceptor->handleCodeIgniterScanCheck();
    //   })
    //
    // Fires before routing/the controller runs, so it's the only place we can
    // answer the /__botversion/scan trigger — by post_controller time, CI4 has
    // already tried (and likely failed) to route that path. CI4's request
    // services aren't guaranteed ready this early, so we read directly from
    // PHP superglobals instead.

    public function handleCodeIgniterScanCheck(): void
    {
        try {
            $path   = '/' . ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

            if ($path !== '/__botversion/scan' || $method !== 'POST') return;

            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $providedKey = $headers['x-botversion-scan-key']
                ?? $headers['X-Botversion-Scan-Key']
                ?? $_SERVER['HTTP_X_BOTVERSION_SCAN_KEY']
                ?? '';

            $result = $this->tryHandleScanTrigger($providedKey);

            http_response_code($result['status']);
            header('Content-Type: application/json');
            echo json_encode(array_filter([
                'success' => $result['success'],
                'result'  => $result['result'] ?? null,
                'error'   => $result['error'] ?? null,
            ], fn($v) => $v !== null));
            exit;

        } catch (\Exception $e) {
            // Silent
        }
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

            $rawBody = null;
            if ($method !== 'GET') {
                $contentType = $request->getHeaderLine('Content-Type');

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode((string) $request->getBody(), true);
                    $rawBody = is_array($decoded) ? $decoded : [];
                } else {
                    $rawBody = $request->getPost() ?? [];
                }
            }

            $statusCode = $response->getStatusCode();
            $this->reportEndpoint($method, $path, $rawBody, $_SERVER['QUERY_STRING'] ?? '', $statusCode);

        } catch (\Exception $e) {
            // Silent
        }
    }

    // =========================================================================
    // ── PLAIN PHP FALLBACK — scan trigger check ───────────────────────────────
    // =========================================================================

    // Called by BotVersion::bootFallback() immediately, before the shutdown
    // hook is registered. Plain PHP has no persistent process, so this is the
    // only moment the SDK can answer the /__botversion/scan trigger — by
    // shutdown time the script has already finished running.
    // Returns true if this request was the scan trigger (caller should exit).

    public function checkAndHandleScanTrigger(): bool
    {
        $path   = '/' . ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($path !== '/__botversion/scan' || $method !== 'POST') return false;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $providedKey = $headers['x-botversion-scan-key']
            ?? $headers['X-Botversion-Scan-Key']
            ?? $_SERVER['HTTP_X_BOTVERSION_SCAN_KEY']
            ?? '';

        $result = $this->tryHandleScanTrigger($providedKey);

        http_response_code($result['status']);
        header('Content-Type: application/json');
        echo json_encode(array_filter([
            'success' => $result['success'],
            'result'  => $result['result'] ?? null,
            'error'   => $result['error'] ?? null,
        ], fn($v) => $v !== null));

        return true;
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

            $rawBody = null;
            if ($method !== 'GET') {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode(file_get_contents('php://input'), true);
                    $rawBody = is_array($decoded) ? $decoded : [];
                } else {
                    $rawBody = $_POST ?? [];
                }
            }

            // Plain PHP has no response object at shutdown — we report regardless
            // of status since we can't check it. http_response_code() gives us the
            // code that was set via header() or http_response_code().
            $statusCode = http_response_code() ?: 200;
            $this->reportEndpoint($method, $path, $rawBody, $_SERVER['QUERY_STRING'] ?? '', $statusCode);

        } catch (\Exception $e) {
            // Silent
        }
    }

    private function extractTrpcGetInputRaw(string $path, string $queryString): ?array
    {
        // tRPC GET requests send their input as a URL-encoded JSON query parameter
        // called 'input'. Returns the raw decoded array as-is — still batched if
        // applicable — WITHOUT merging different procedures' fields together.
        // Splitting per-procedure happens in reportEndpoint(). Only activates for
        // tRPC paths — safe for all REST endpoints.
        if (strpos($path, '/trpc/') === false) return null;
        if (empty($queryString) || strpos($queryString, 'input=') === false) return null;

        try {
            parse_str($queryString, $params);
            $inputParam = $params['input'] ?? null;
            if (!$inputParam) return null;

            $decoded = json_decode(urldecode($inputParam), true);
            if (!is_array($decoded)) return null;

            return $decoded;

        } catch (\Exception $e) {
            return null;
        }
    }

    // Unwraps a single (non-batched) tRPC/superjson envelope:
    // { "json": {...realFields...} } -> {...realFields...}
    private function unwrapTrpcJsonEnvelope(array $obj): array
    {
        if (isset($obj['json']) && is_array($obj['json'])) {
            return $obj['json'];
        }
        return $obj;
    }

    // tRPC batches multiple procedure calls into one URL, e.g.
    //   /api/trpc/me.get,getUserTopBanners,bookingUnconfirmedCount
    // tRPC is always mounted at a fixed base containing "/trpc/". Everything
    // after that marker is the comma-separated procedure list, and each
    // comma-separated piece is ALREADY a complete procedure path on its own
    // (it may itself contain "/" for nested routers) — it must never have
    // another procedure's prefix re-attached to it.
    // Returns a single-item array unchanged if the path isn't a batch.
    private function splitBatchPath(string $rawPath): array
    {
        $marker = '/trpc/';
        $idx = strpos($rawPath, $marker);
        if ($idx === false) return [$rawPath];

        $base = substr($rawPath, 0, $idx + strlen($marker));
        $tail = substr($rawPath, $idx + strlen($marker));

        if (strpos($tail, ',') === false) return [$rawPath];

        $procs = array_filter(array_map('trim', explode(',', $tail)), fn($p) => $p !== '');
        return array_map(fn($p) => $base . $p, array_values($procs));
    }

    // Splits a batched tRPC request body/input — { "0": {...}, "1": {...} } —
    // into a list of individual bodies, in the same order as the batch keys,
    // which lines up with the order splitBatchPath() returns procedure names
    // in. Each slot is unwrapped from its own { "json": {...} } envelope.
    // Returns a single-item array unchanged if the body isn't actually batched.
    private function splitBatchBody(array $bodyObj): array
    {
        $keys = array_keys($bodyObj);
        $isBatch = count($keys) > 0 && count(array_filter($keys, 'is_numeric')) === count($keys);

        if (!$isBatch) return [$bodyObj];

        $orderedKeys = $keys;
        sort($orderedKeys, SORT_NUMERIC);

        $slots = [];
        foreach ($orderedKeys as $k) {
            $entry = $bodyObj[$k];
            $slots[] = is_array($entry) ? $this->unwrapTrpcJsonEnvelope($entry) : $entry;
        }
        return $slots;
    }

    // Splits a (possibly batched) tRPC path into one clean endpoint per real
    // procedure — instead of one garbled, comma-joined path — with each
    // procedure's own body fields only, never mixed with another procedure's.
    // Falls back to reporting a single endpoint unchanged when the path
    // isn't a batch. This replaces calling maybeReport() directly.
    private function reportEndpoint(string $method, string $path, ?array $bodyData, string $queryString, int $statusCode): void
    {
        $splitPaths = array_map(fn($p) => $this->normalizePath($p), $this->splitBatchPath($path));

        $bodySlots = null;

        // tRPC GET requests carry their input in the query string, not the body
        if (empty($bodyData) && $queryString !== '') {
            $rawInput = $this->extractTrpcGetInputRaw($path, $queryString);
            if ($rawInput !== null) {
                $bodySlots = $this->splitBatchBody($rawInput);
            }
        }

        if ($bodySlots === null) {
            $bodySlots = count($splitPaths) > 1
                ? $this->splitBatchBody($bodyData ?? [])
                : [$bodyData];
        }

        foreach ($splitPaths as $i => $singlePath) {
            $slotBody = $bodySlots[$i] ?? null;
            $bodyStructure = !empty($slotBody) ? $this->buildBodyStructure($slotBody) : null;
            $this->maybeReport($method, $singlePath, $bodyStructure, $statusCode);
        }
    }

    // =========================================================================
    // ── SHARED HELPERS ────────────────────────────────────────────────────────
    // =========================================================================

    // Verifies the scan-trigger secret and runs the scan if valid.
    // Shared by every framework entry point below.
    private function tryHandleScanTrigger(string $providedKey): array
    {
        if (
            !empty($this->options['scan_secret']) &&
            $providedKey === $this->options['scan_secret'] &&
            isset($this->options['on_scan_requested']) &&
            is_callable($this->options['on_scan_requested'])
        ) {
            try {
                $result = ($this->options['on_scan_requested'])();
                return ['success' => true, 'result' => $result, 'status' => 200];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage(), 'status' => 500];
            }
        }

        return ['success' => false, 'error' => 'Unauthorized', 'status' => 401];
    }

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
            } elseif (is_null($val)) {
                $structure[$key] = 'null';
            } elseif (is_bool($val)) {
                $structure[$key] = 'boolean';
            } elseif (is_int($val) || is_float($val)) {
                $structure[$key] = 'number';
            } elseif (is_array($val) && array_keys($val) !== range(0, count($val) - 1)) {
                // Associative array = object — capture one level of nested properties
                // so agent can reconstruct shape e.g. group: { teamId: null, parentId: null }
                $nestedProps = [];
                foreach ($val as $nk => $nv) {
                    if (is_null($nv)) {
                        $nestedProps[$nk] = ['type' => 'string'];
                    } elseif (is_bool($nv)) {
                        $nestedProps[$nk] = ['type' => 'boolean'];
                    } elseif (is_int($nv) || is_float($nv)) {
                        $nestedProps[$nk] = ['type' => 'number'];
                    } else {
                        $nestedProps[$nk] = ['type' => 'string'];
                    }
                }
                $structure[$key] = !empty($nestedProps)
                    ? ['type' => 'object', 'properties' => $nestedProps]
                    : 'object';
            } elseif (is_array($val)) {
                // Sequential array = plain array
                $structure[$key] = 'array';
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
        foreach ($bodyStructure as $key => $typeOrObj) {
            // Nested object captured with properties
            if (is_array($typeOrObj) && isset($typeOrObj['type'])) {
                $properties[$key] = $typeOrObj;
            // Simple type string
            } elseif ($typeOrObj === 'null' || $typeOrObj === '[redacted]') {
                $properties[$key] = ['type' => 'string'];
            } else {
                $properties[$key] = ['type' => $typeOrObj];
            }
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function reportAsync(string $method, string $path, ?array $jsonSchema): void
    {
        // If running under PHP-FPM, send the response to the visitor first,
        // then do the reporting call — the visitor doesn't wait on it at all.
        // Falls back to a short-timeout blocking call everywhere else (plain
        // PHP / CLI / servers without FPM), same trade-off as before.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            $this->client->updateEndpoint([
                'method'      => $method,
                'path'        => $path,
                'requestBody' => $jsonSchema,
                'detectedBy'  => 'runtime',
            ]);
        } catch (\Exception $e) {
            // Silent — never break the app
        }
    }
}