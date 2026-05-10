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
     *     'debug'      => true,
     *     'exclude'    => ['/health', '/internal'],
     *     'api_prefix' => '/api',
     *   ]);
     */
    public static function init(string $apiKey, array $options = []): void
    {
        if (self::$initialized) {
            if (!self::$client) return;
            // Re-attach middleware in case of framework reload
            $framework = self::detectFramework();
            if ($framework === 'laravel') {
                self::attachLaravelMiddleware([
                    'exclude'    => self::$options['exclude'] ?? [],
                    'api_prefix' => self::$options['api_prefix'] ?? null,
                    'debug'      => self::$options['debug'] ?? false,
                ]);
            }
            return;
        } 

        if (empty($apiKey)) {
            return;
        }

        self::$initialized = true;
        self::$options     = $options;
        $debug             = $options['debug'] ?? false;

        self::$client = new BotVersionClient([
            'api_key'      => $apiKey,
            'platform_url' => $options['platform_url'] ?? 'https://botversion.com',
            'debug'        => $debug,
            'timeout'      => $options['timeout'] ?? 5,
        ]);

        // ── Detect framework ─────────────────────────────────────────────────
        $framework = self::detectFramework();

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

                    $endpoints = BotVersionScanner::scanLaravelRoutes();

                    if (empty($endpoints)) {
                        return;
                    }

                    self::$client->registerEndpoints($endpoints);
                    $patterns = BotVersionScanner::scanFrontendRoutes();
                    if (!empty($patterns)) {
                        self::$client->registerRoutePatterns($patterns);
                    }
                } catch (\Exception $e) {
                    if ($debug) {
                        error_log('[botversion] Static scan failed: ' . $e->getMessage());
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

    // ── Framework detection ───────────────────────────────────────────────────

    private static function detectFramework(): ?string
    {
        if (function_exists('app') && class_exists('\Illuminate\Foundation\Application')) {
            return 'laravel';
        }
        if (class_exists('\Symfony\Component\HttpKernel\Kernel')) {
            return 'symfony';
        }
        return null;
    }

    // ── Attach Laravel middleware ─────────────────────────────────────────────

    private static function attachLaravelMiddleware(array $options): void
    {
        try {
            $interceptor = new BotVersionInterceptor(self::$client, $options);

            app()->instance(BotVersionInterceptor::class, $interceptor);

            app(\Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('web', BotVersionInterceptor::class);
            app(\Illuminate\Contracts\Http\Kernel::class)->appendMiddlewareToGroup('api', BotVersionInterceptor::class);
        } catch (\Exception $e) {
        }
    }
}
