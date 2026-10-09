<?php
// botversion-sdk-php/Interceptor.php

class BotVersionInterceptor
{
    private $client;
    private $options;
    private static $reported = [];

    // Reply-shape capture: in-process cache of per-endpoint state (also saved to temp files)
    private static $responseState = [];

    private const MAX_RESPONSE_ATTEMPTS      = 5;
    private const MAX_RESPONSE_CAPTURE_BYTES = 262144; // 256 KB, bigger replies are skipped
    private const MAX_RESPONSE_DEPTH         = 4;
    private const MAX_RESPONSE_FIELDS        = 50;
    private const RESPONSE_STATE_TTL         = 86400;  // re-send a shape after 24h

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
        '/__botversion',
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

        // Drops cache-validation headers so the app sends a full reply instead of an empty 304
        // (which has no body to read). Only runs while this endpoint still has no captured shape.
        if ($this->isResponseShapeNeeded($method, $path)) {
            $request->headers->remove('If-None-Match');
            $request->headers->remove('If-Modified-Since');
        }

        $response = $next($request);

        if (!$this->shouldIgnore($path)) {
            $apiPrefix = $this->options['api_prefix'] ?? null;

            if (!$apiPrefix || str_starts_with($path, $apiPrefix)) {
                $rawBody = $method !== 'GET'
                    ? $request->except(['_token', '_method'])
                    : null;

                $this->reportEndpoint(
                    $method, $path, $rawBody, $request->getQueryString() ?? '', $response->getStatusCode(),
                    $response->headers->get('Content-Type', ''),
                    $this->readHttpFoundationBody($response)
                );
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

            // Drops cache-validation headers so the app sends a full reply instead of an empty 304
            // (which has no body to read). Only runs while this endpoint still has no captured shape.
            if ($this->isResponseShapeNeeded($method, $path)) {
                $request->headers->remove('If-None-Match');
                $request->headers->remove('If-Modified-Since');
            }

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

            $this->reportEndpoint(
                $method, $path, $rawBody, $request->getQueryString() ?? '', $response->getStatusCode(),
                $response->headers->get('Content-Type', ''),
                $this->readHttpFoundationBody($response)
            );

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

        // Drops cache-validation headers so the app sends a full reply instead of an empty 304
        // (which has no body to read). Only runs while this endpoint still has no captured shape.
        if ($this->isResponseShapeNeeded($method, $path)) {
            $request = $request->withoutHeader('If-None-Match')->withoutHeader('If-Modified-Since');
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
            $this->reportEndpoint(
                $method, $path, $rawBody, $queryString, $response->getStatusCode(),
                $response->getHeaderLine('Content-Type'),
                $this->readPsr7Body($response)
            );

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
            $ciBody     = $response->getBody();
            $this->reportEndpoint(
                $method, $path, $rawBody, $_SERVER['QUERY_STRING'] ?? '', $statusCode,
                $response->getHeaderLine('Content-Type'),
                is_string($ciBody) ? $ciBody : null
            );

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
    private function reportEndpoint(string $method, string $path, ?array $bodyData, string $queryString, int $statusCode, ?string $responseContentType = null, $responseBody = null): void
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

        // Capture the shape of the successful JSON reply (field names only).
        // Runs after the request report so the endpoint is always registered first.
        $this->maybeReportResponse($method, $path, $statusCode, $responseContentType, $responseBody);
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

    // True while at least one endpoint behind this path still has no captured reply shape
    private function isResponseShapeNeeded(string $method, string $path): bool
    {
        try {
            if ($this->shouldIgnore($path)) return false;

            $apiPrefix = $this->options['api_prefix'] ?? null;
            if ($apiPrefix && !str_starts_with($path, $apiPrefix)) return false;

            if (strpos($path, '/__botversion/') === 0) return false;

            foreach ($this->splitBatchPath($path) as $rawPath) {
                $state = $this->getResponseState($method, $this->normalizePath($rawPath));
                if (!$state['done'] && $state['attempts'] < self::MAX_RESPONSE_ATTEMPTS) return true;
            }
        } catch (\Throwable $e) {
            // Silent, never break the app
        }
        return false;
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

        // =========================================================================
    // ── RESPONSE SHAPE CAPTURE ────────────────────────────────────────────────
    // =========================================================================
    // Records only field names and types of successful JSON replies (never
    // values) so the platform knows what each endpoint returns.

    // The ONE method every framework reaches once it has the app's reply.
    // $body may be a string or null (null = reply could not be read safely,
    // e.g. a stream or file download: skipped, but counted as an attempt).
    private function maybeReportResponse(string $method, string $path, int $statusCode, ?string $contentType, $body): void
    {
        try {
            if ($statusCode < 200 || $statusCode >= 300) return;

            // Our own scan-trigger route must never be reported as one of the host's endpoints
            if (strpos($path, '/__botversion/') === 0) return;

            $paths = array_map(fn($p) => $this->normalizePath($p), $this->splitBatchPath($path));

            // Which of these endpoints still need a reply shape?
            $pending = [];
            foreach ($paths as $p) {
                $state = $this->getResponseState($method, $p);
                if (!$state['done'] && $state['attempts'] < self::MAX_RESPONSE_ATTEMPTS) {
                    $pending[] = $p;
                }
            }
            if (empty($pending)) return;

            // Count this reply as an attempt so endpoints with no usable shape are eventually dropped
            foreach ($pending as $p) {
                $state = $this->getResponseState($method, $p);
                $state['attempts']++;
                $this->saveResponseState($method, $p, $state);
            }

            if (!is_string($body) || $body === '' || strlen($body) > self::MAX_RESPONSE_CAPTURE_BYTES) return;

            $ct = strtolower((string) $contentType);
            if ($ct !== '' && strpos($ct, 'json') === false) return;

            $parsed = json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // The host app may have compressed the reply (gzip / deflate / brotli)
                $raw = null;
                if (substr($body, 0, 2) === "\x1f\x8b" && function_exists('gzdecode')) {
                    $raw = @gzdecode($body, 1048576);
                } elseif (substr($body, 0, 1) === "\x78" && function_exists('gzuncompress')) {
                    $raw = @gzuncompress($body, 1048576);
                } elseif (function_exists('brotli_uncompress')) {
                    $raw = @brotli_uncompress($body, 1048576);
                }
                if (!is_string($raw) || $raw === '') return;
                $parsed = json_decode($raw);
                if (json_last_error() !== JSON_ERROR_NONE) return;
            }

            $this->reportResponseShape($method, $path, $parsed);
        } catch (\Throwable $e) {
            // Silent, never break the app
        }
    }

    // Turns an already-parsed reply into field-name shapes and reports each new one
    private function reportResponseShape(string $method, string $rawPath, $parsed): void
    {
        if (!is_object($parsed) && !is_array($parsed)) return;

        $isTrpc = strpos($rawPath, '/trpc/') !== false;
        $paths  = array_map(fn($p) => $this->normalizePath($p), $this->splitBatchPath($rawPath));

        if (count($paths) > 1) {
            if (!$isTrpc || !is_array($parsed) || count($parsed) !== count($paths)) return;
            $slots = array_map(fn($e) => $this->unwrapTrpcResult($e), array_values($parsed));
        } else {
            $slots = [$isTrpc ? $this->unwrapTrpcResult($parsed) : $parsed];
        }

        foreach ($paths as $i => $p) {
            $slot = $slots[$i] ?? null;
            if (!is_object($slot) && !is_array($slot)) continue;

            $state = $this->getResponseState($method, $p);
            if ($state['done']) continue;

            $schema = $this->describeResponseValue($slot, 0);
            if (!$this->hasResponseFields($schema)) continue;

            $state['done'] = true;
            $this->saveResponseState($method, $p, $state);

            $this->client->updateEndpoint([
                'method'       => $method,
                'path'         => $p,
                'requestBody'  => null,
                'responseBody' => $schema,
                'detectedBy'   => 'runtime',
            ]);
        }
    }

    // JSON is decoded as objects (not arrays) so an empty {} and an empty [] stay distinguishable
    private function describeResponseValue($val, int $depth = 0): array
    {
        if ($val === null) return ['type' => 'string'];

        if (is_array($val)) {
            if ($depth >= self::MAX_RESPONSE_DEPTH || count($val) === 0) {
                return ['type' => 'array', 'items' => ['type' => 'object']];
            }
            return [
                'type'  => 'array',
                'items' => $this->describeArrayItems(array_slice($val, 0, 5), $depth + 1),
            ];
        }

        if (is_object($val)) {
            $vars = get_object_vars($val);
            if ($depth >= self::MAX_RESPONSE_DEPTH) return ['type' => 'object'];
            foreach (array_keys($vars) as $k) {
                if ($this->looksLikeIdKey($k)) return ['type' => 'object'];
            }
            $properties = [];
            foreach (array_slice($vars, 0, self::MAX_RESPONSE_FIELDS, true) as $k => $v) {
                $properties[(string) $k] = $this->describeResponseValue($v, $depth + 1);
            }
            return $properties ? ['type' => 'object', 'properties' => $properties] : ['type' => 'object'];
        }

        if (is_bool($val)) return ['type' => 'boolean'];
        if (is_int($val) || is_float($val)) return ['type' => 'number'];
        return ['type' => 'string'];
    }

    // Combines the first few list items so fields missing from one item still appear
    private function describeArrayItems(array $items, int $depth): array
    {
        $objs = array_values(array_filter($items, 'is_object'));
        if (empty($objs)) {
            foreach ($items as $item) {
                if ($item !== null) return $this->describeResponseValue($item, $depth);
            }
            return ['type' => 'object'];
        }

        $merged = [];
        foreach ($objs as $o) {
            foreach (array_slice(get_object_vars($o), 0, self::MAX_RESPONSE_FIELDS, true) as $k => $v) {
                if (!isset($merged[$k])) $merged[$k] = $v;
            }
        }
        return $this->describeResponseValue((object) $merged, $depth);
    }

    // A shape with no fields (empty list, {}) is not useful, so keep waiting for a better reply
    private function hasResponseFields($schema): bool
    {
        if (!is_array($schema)) return false;
        if (($schema['type'] ?? '') === 'array') {
            return $this->hasResponseFields($schema['items'] ?? null);
        }
        return !empty($schema['properties']);
    }

    // Keys that look like record IDs or emails are never sent as field names
    private function looksLikeIdKey($key): bool
    {
        $key = (string) $key;
        return (bool) (
            preg_match('/^\d+$/', $key)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)
            || preg_match('/^[0-9a-f]{24}$/i', $key)
            || preg_match('/^c[a-z0-9]{20,}$/i', $key)
            || (strlen($key) >= 16 && preg_match('/[a-zA-Z]/', $key) && preg_match('/[0-9]/', $key))
            || strpos($key, '@') !== false
        );
    }

    // tRPC wraps each result as { result: { data: { json: ... } } }; errors as { error }
    private function unwrapTrpcResult($entry)
    {
        if (is_object($entry)) {
            if (!empty($entry->error) && empty($entry->result)) return null;
            if (isset($entry->result) && is_object($entry->result) && property_exists($entry->result, 'data')) {
                $data = $entry->result->data;
                return (is_object($data) && property_exists($data, 'json')) ? $data->json : $data;
            }
        }
        return $entry;
    }

    // ── Per-endpoint memory (survives between PHP requests) ──────────────────

    private function responseStateFile(string $method, string $path): string
    {
        $secret = (string) ($this->options['scan_secret'] ?? '');
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'botversion_resp_' . md5($secret . '|' . $method . ':' . $path);
    }

    private function getResponseState(string $method, string $path): array
    {
        $key = $method . ':' . $path;
        if (isset(self::$responseState[$key])) return self::$responseState[$key];

        $state = ['done' => false, 'attempts' => 0];
        try {
            $file = $this->responseStateFile($method, $path);
            if (is_file($file) && (time() - (int) @filemtime($file)) < self::RESPONSE_STATE_TTL) {
                $raw = trim((string) @file_get_contents($file));
                if ($raw === 'done') {
                    $state['done'] = true;
                } else {
                    $state['attempts'] = (int) $raw;
                }
            }
        } catch (\Throwable $e) {
            // Silent, falls back to in-memory state only
        }

        return self::$responseState[$key] = $state;
    }

    private function saveResponseState(string $method, string $path, array $state): void
    {
        self::$responseState[$method . ':' . $path] = $state;
        try {
            @file_put_contents(
                $this->responseStateFile($method, $path),
                $state['done'] ? 'done' : (string) $state['attempts'],
                LOCK_EX
            );
        } catch (\Throwable $e) {
            // Silent
        }
    }

    // ── Readers: get the reply body out of each kind of response object ──────

    // Laravel, Lumen and Symfony replies. Streams and file downloads are skipped on purpose.
    private function readHttpFoundationBody($response): ?string
    {
        try {
            if (!is_object($response) || !method_exists($response, 'getContent')) return null;
            if (
                $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse ||
                $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ) {
                return null;
            }
            $content = $response->getContent();
            return is_string($content) ? $content : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Slim (PSR-7) replies. The stream position is put back so the app's reply is unchanged.
    private function readPsr7Body($response): ?string
    {
        try {
            if (!is_object($response) || !method_exists($response, 'getBody')) return null;
            $stream = $response->getBody();
            $size   = $stream->getSize();
            if ($size === null || $size > self::MAX_RESPONSE_CAPTURE_BYTES || !$stream->isSeekable()) return null;

            $position = $stream->tell();
            $stream->rewind();
            $content = $stream->getContents();
            $stream->seek($position);

            return is_string($content) ? $content : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
