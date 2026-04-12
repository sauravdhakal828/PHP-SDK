<?php
// botversion-sdk-php/BotVersion.php

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Scanner.php';
require_once __DIR__ . '/Interceptor.php';

class BotVersion
{
    private static $initialized = false;
    private static $client      = null;
    private static $options     = [];

    /**
     * Initialize the BotVersion SDK.
     *
     * Usage (in AppServiceProvider::boot()):
     *   BotVersion::init('YOUR_API_KEY');
     *
     * Optional config:
     *   BotVersion::init('YOUR_API_KEY', [
     *     'debug'            => true,
     *     'exclude'          => ['/health', '/internal'],
     *     'api_prefix'       => '/api',
     *     'get_user_context' => fn($request) => ['userId' => $request->user()?->id],
     *   ]);
     */
    public static function init(string $apiKey, array $options = []): void
    {
        if (self::$initialized) {
            error_log("[BotVersion SDK] ⚠ Already initialized — skipping");
            return;
        }

        if (empty($apiKey)) {
            error_log("[BotVersion SDK] ❌ api_key is required.");
            return;
        }

        self::$initialized = true;
        self::$options     = $options;
        $debug             = $options['debug'] ?? false;

        if ($debug) {
            error_log("[BotVersion SDK] Initializing...");
        }

        self::$client = new BotVersionClient([
            'api_key'      => $apiKey,
            'platform_url' => $options['platform_url'] ?? 'https://app.botversion.com',
            'debug'        => $debug,
            'timeout'      => $options['timeout'] ?? 5,
        ]);

        // ── Detect framework ─────────────────────────────────────────────────
        $framework = self::detectFramework();

        if (!$framework) {
            error_log("[BotVersion SDK] ❌ Could not detect framework. Only Laravel is currently supported.");
            return;
        }

        if ($debug) {
            error_log("[BotVersion SDK] ✅ Framework detected: {$framework}");
        }

        $interceptorOptions = [
            'exclude'    => $options['exclude'] ?? [],
            'api_prefix' => $options['api_prefix'] ?? null,
            'debug'      => $debug,
        ];

        // ── Register Laravel middleware ───────────────────────────────────────
        if ($framework === 'laravel') {
            self::attachLaravelMiddleware($interceptorOptions);
        }

        // ── Static scan (delayed via booted callback) ─────────────────────────
        if (function_exists('app') && method_exists(app(), 'booted')) {
            app()->booted(function () use ($debug) {
                try {
                    if ($debug) {
                        error_log("[BotVersion SDK] Scanning Laravel routes...");
                    }

                    $endpoints = BotVersionScanner::scanLaravelRoutes();

                    if ($debug) {
                        error_log("[BotVersion SDK] Found " . count($endpoints) . " routes");
                    }

                    if (empty($endpoints)) {
                        error_log("[BotVersion SDK] ⚠ No endpoints found.");
                        error_log("[BotVersion SDK] ⚠ Make sure BotVersion::init() is called in a ServiceProvider.");
                        return;
                    }

                    self::$client->registerEndpoints($endpoints);

                    if ($debug) {
                        error_log("[BotVersion SDK] ✅ Initialization complete — " . count($endpoints) . " endpoints registered");
                    }
                } catch (\Exception $e) {
                    if ($debug) {
                        error_log("[BotVersion SDK] ⚠ Scan error: " . $e->getMessage());
                    }
                }
            });
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public static function getEndpoints(): array
    {
        if (!self::$client) {
            throw new \RuntimeException("BotVersion SDK not initialized. Call BotVersion::init() first.");
        }
        return self::$client->getEndpoints();
    }

    public static function registerEndpoint(array $endpoint): void
    {
        if (!self::$client) {
            throw new \RuntimeException("BotVersion SDK not initialized.");
        }
        self::$client->registerEndpoints([$endpoint]);
    }

    /**
     * Handle a chat request from the widget.
     *
     * Usage in a Laravel controller:
     *   return BotVersion::chat($request);
     *
     * Or with executeAgentCall for tool execution:
     *   return BotVersion::chat($request, true);
     */
    public static function chat($request, bool $executeTools = false)
    {
        if (!function_exists('response')) {
            throw new \RuntimeException("[BotVersion SDK] chat() requires Laravel.");
        }
        if (!self::$client) {
            return response()->json(['error' => 'BotVersion SDK not initialized.'], 500);
        }

        $getUserContext = self::$options['get_user_context'] ?? null;
        $userContext    = $getUserContext
            ? $getUserContext($request)
            : self::extractDefaultContext($request);

        try {
            $response = self::$client->agentChat([
                'chatbotId'           => $request->input('chatbotId'),
                'publicKey'           => $request->input('publicKey'),
                'message'             => $request->input('message', ''),
                'conversationHistory' => $request->input('conversationHistory', []),
                'pageContext'         => $request->input('pageContext', []),
                'userContext'         => $userContext,
            ]);

            // Plain chat/greeting response
            if (isset($response['answer'])) {
                return response()->json(['action' => 'RESPOND', 'message' => $response['answer']]);
            }

            // If tool execution is disabled or not needed, return as-is
            if (!$executeTools || ($response['action'] ?? '') !== 'EXECUTE_CALL') {
                return response()->json($response);
            }

            // Execute tool call loop (mirrors JS executeAgentCall)
            $result = self::makeLocalCall($request, $response['call']);

            $toolResponse = self::$client->agentToolResult(
                $response['sessionToken'],
                $result,
                $response['sessionData'] ?? null
            );

            // Handle second tool call if needed
            if (($toolResponse['action'] ?? '') === 'EXECUTE_CALL') {
                $result2      = self::makeLocalCall($request, $toolResponse['call']);
                $toolResponse = self::$client->agentToolResult(
                    $toolResponse['sessionToken'],
                    $result2,
                    $toolResponse['sessionData'] ?? null
                );
            }

            return response()->json($toolResponse);

        } catch (\Exception $e) {
            error_log("[BotVersion SDK] chat error: " . $e->getMessage());
            return response()->json(['error' => 'Agent error'], 500);
        }
    }

    /**
     * Execute the agent's requested API call locally on this server,
     * forwarding the user's real auth headers — mirrors JS makeLocalCall()
     */
    public static function makeLocalCall($request, array $call): array
    {
        $method  = strtoupper($call['method'] ?? 'GET');
        $path    = $call['path'] ?? '/';
        $body    = $call['body'] ?? null;

        // Build the full local URL
        $scheme = $request->secure() ? 'https' : 'http';
        $host   = $request->getHttpHost(); // includes port if non-standard
        $url    = $scheme . '://' . $host . $path;

        $headers = [
            'Content-Type: application/json',
            // Forward the user's real auth token
            'Authorization: ' . ($request->header('Authorization') ?? ''),
            'Cookie: ' . ($request->header('Cookie') ?? ''),
        ];

        $bodyJson = $body ? json_encode($body) : null;

        if ($bodyJson) {
            $headers[] = 'Content-Length: ' . strlen($bodyJson);
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $bodyJson,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        // Get status code from response headers
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $matches);
        $statusCode = (int)($matches[1] ?? 500);

        if ($response === false) {
            return ['status' => 500, 'error' => 'Local call failed: ' . $url];
        }

        $parsed = json_decode($response, true);

        return [
            'status' => $statusCode,
            'data'   => (json_last_error() === JSON_ERROR_NONE) ? $parsed : ['raw' => $response],
        ];
    }

    // ── Framework detection ───────────────────────────────────────────────────

    private static function detectFramework(): ?string
    {
        if (function_exists('app') && class_exists('\Illuminate\Foundation\Application')) {
            return 'laravel';
        }
        return null;
    }

    // ── Attach Laravel middleware ─────────────────────────────────────────────

    private static function attachLaravelMiddleware(array $options): void
    {
        try {
            $interceptor = new BotVersionInterceptor(self::$client, $options);

            // Bind the pre-built interceptor into the container
            app()->instance(BotVersionInterceptor::class, $interceptor);

            // Push as global middleware via the router instead of kernel
            app(\Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('web', BotVersionInterceptor::class);
            app(\Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('api', BotVersionInterceptor::class);

            if ($options['debug'] ?? false) {
                error_log("[BotVersion SDK] ✅ Laravel middleware attached");
                }
        } catch (\Exception $e) {
            error_log("[BotVersion SDK] ⚠ Failed to attach middleware: " . $e->getMessage());
        }
    }

    // ── Default user context extraction ──────────────────────────────────────
    // Mirrors JS extractDefaultContext() — flattens, strips sensitive keys, smart aliases

    private static function extractDefaultContext($request): array
    {
        $user = null;

        // Try Laravel Auth
        if (function_exists('auth') && auth()->check()) {
            $user = auth()->user()?->toArray() ?? [];
        }

        // Fallback to request user attribute
        if (empty($user)) {
            $user = $request->user()?->toArray() ?? [];
        }

        if (empty($user)) return [];

        $sensitiveKeys = [
            'password', 'passwd', 'pwd', 'token', 'accesstoken', 'refreshtoken',
            'bearertoken', 'secret', 'privatesecret', 'apikey', 'api_key',
            'privatekey', 'private_key', 'signingkey', 'hash', 'passwordhash',
            'salt', 'cvv', 'ssn', 'pin', 'creditcard', 'credit_card',
            'cardnumber', 'card_number', 'otp', 'mfa', 'totp',
            'image', 'avatar', 'photo',
        ];

        // Step 1: Flatten nested arrays
        $flatUser = self::flattenArray($user);

        // Step 2: Strip sensitive keys
        $context = [];
        foreach ($flatUser as $key => $value) {
            $isSensitive = false;
            foreach ($sensitiveKeys as $sk) {
                if (str_contains(strtolower($key), $sk)) {
                    $isSensitive = true;
                    break;
                }
            }
            if (!$isSensitive) {
                $context[$key] = $value;
            }
        }

        // Step 3: Smart aliasing — create clean aliases for ID-like fields
        $idSuffixes    = ['id', 'key', 'code', 'ref', 'slug', 'uuid', 'num', 'no'];
        $cleanPrefixes = ['active', 'current', 'selected', 'default', 'my', 'the', 'this'];

        foreach (array_keys($context) as $key) {
            $lowerKey  = strtolower($key);
            $isIdField = false;
            foreach ($idSuffixes as $suffix) {
                if (str_ends_with($lowerKey, $suffix)) {
                    $isIdField = true;
                    break;
                }
            }

            if ($isIdField && !empty($context[$key])) {
                $cleanKey = $key;
                foreach ($cleanPrefixes as $prefix) {
                    if (stripos($cleanKey, $prefix) === 0) {
                        $cleanKey = lcfirst(substr($cleanKey, strlen($prefix)));
                        break;
                    }
                }
                if ($cleanKey !== $key && !isset($context[$cleanKey])) {
                    $context[$cleanKey] = $context[$key];
                }
            }
        }

        return $context;
    }

    private static function flattenArray(array $arr, string $prefix = ''): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            $fullKey = $prefix ? $prefix . '_' . $key : $key;
            if (is_null($value) || $value === '') continue;
            if (is_array($value)) {
                $nested = self::flattenArray($value, $fullKey);
                foreach ($nested as $k => $v) {
                    $result[$k] = $v;
                }
            } else {
                $result[$fullKey] = $value;
            }
        }
        return $result;
    }
}