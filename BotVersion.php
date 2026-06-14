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
     * Laravel/Lumen  — in AppServiceProvider::boot():
     *   BotVersion::init('YOUR_API_KEY');
     *
     * Symfony — in Kernel.php or a CompilerPass:
     *   BotVersion::init('YOUR_API_KEY');
     *
     * Slim — in index.php after building $app, pass the app instance:
     *   BotVersion::init('YOUR_API_KEY', ['slim_app' => $app]);
     *
     * CodeIgniter 4 — in app/Config/Events.php:
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

            // Re-attach middleware on framework reload (Laravel/Lumen only)
            $framework = self::detectFramework();
            if ($framework === 'laravel' || $framework === 'lumen') {
                self::attachLaravelMiddleware([
                    'exclude'    => self::$options['exclude'] ?? [],
                    'api_prefix' => self::$options['api_prefix'] ?? null,
                    'debug'      => self::$options['debug'] ?? false,
                ]);
            }
            return;
        }

        if (empty($apiKey)) return;

        self::$initialized = true;
        self::$options     = $options;
        $debug             = $options['debug'] ?? false;

        self::$client = new BotVersionClient([
            'api_key'      => $apiKey,
            'platform_url' => $options['platform_url'] ?? 'https://botversion.com',
            'debug'        => $debug,
            'timeout'      => $options['timeout'] ?? 5,
        ]);

        $framework = self::detectFramework($options);

        $interceptorOptions = [
            'exclude'    => $options['exclude'] ?? [],
            'api_prefix' => $options['api_prefix'] ?? null,
            'debug'      => $debug,
        ];

        // ── Attach interceptor per framework ──────────────────────────────────
        switch ($framework) {

            case 'laravel':
                self::attachLaravelMiddleware($interceptorOptions);
                self::bootLaravel($debug);
                break;

            case 'lumen':
                self::attachLumenMiddleware($interceptorOptions);
                self::bootLumen($debug);
                break;

            case 'symfony':
                self::bootSymfony($interceptorOptions, $debug);
                break;

            case 'slim':
                self::bootSlim($options['slim_app'] ?? null, $interceptorOptions, $debug);
                break;

            case 'codeigniter':
                self::bootCodeIgniter($interceptorOptions, $debug);
                break;

            default:
                if ($debug) {
                    error_log('[botversion] Unknown framework — runtime interception only via shutdown hook.');
                }
                // Fallback: best-effort shutdown-based interception for plain PHP
                self::bootFallback($interceptorOptions, $debug);
                break;
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

    private static function detectFramework(array $options = []): ?string
    {
        // Explicit override — user can force a framework
        if (!empty($options['framework'])) {
            return strtolower($options['framework']);
        }

        // Laravel — full stack
        if (
            function_exists('app') &&
            class_exists('\Illuminate\Foundation\Application') &&
            (app() instanceof \Illuminate\Foundation\Application)
        ) {
            return 'laravel';
        }

        // Lumen — Laravel's micro-framework
        if (
            function_exists('app') &&
            class_exists('\Laravel\Lumen\Application') &&
            (app() instanceof \Laravel\Lumen\Application)
        ) {
            return 'lumen';
        }

        // Symfony
        if (class_exists('\Symfony\Component\HttpKernel\HttpKernel')) {
            return 'symfony';
        }

        // Slim 4
        if (class_exists('\Slim\App')) {
            return 'slim';
        }

        // CodeIgniter 4
        if (
            class_exists('\CodeIgniter\CodeIgniter') ||
            defined('SYSTEMPATH') ||
            function_exists('\\CodeIgniter\\boot')
        ) {
            return 'codeigniter';
        }

        return null;
    }

    // =========================================================================
    // ── LARAVEL ───────────────────────────────────────────────────────────────
    // =========================================================================

    private static function attachLaravelMiddleware(array $options): void
    {
        try {
            $interceptor = new BotVersionInterceptor(self::$client, $options);
            app()->instance(BotVersionInterceptor::class, $interceptor);
            app(\Illuminate\Contracts\Http\Kernel::class)
                ->appendMiddlewareToGroup('web', BotVersionInterceptor::class);
            app(\Illuminate\Contracts\Http\Kernel::class)
                ->appendMiddlewareToGroup('api', BotVersionInterceptor::class);
        } catch (\Exception $e) {
            // Silent — middleware attach is best-effort
        }
    }

    private static function bootLaravel(bool $debug): void
    {
        if (!function_exists('app') || !method_exists(app(), 'booted')) return;

        app()->booted(function () use ($debug) {
            try {
                $endpoints = BotVersionScanner::scanLaravelRoutes();
                if (!empty($endpoints)) {
                    self::$client->registerEndpoints($endpoints);
                }
                $patterns = BotVersionScanner::scanFrontendRoutes();
                if (!empty($patterns)) {
                    self::$client->registerRoutePatterns($patterns);
                }
            } catch (\Exception $e) {
                if ($debug) error_log('[botversion:laravel] Scan failed: ' . $e->getMessage());
            }
        });
    }

    // =========================================================================
    // ── LUMEN ─────────────────────────────────────────────────────────────────
    // =========================================================================

    private static function attachLumenMiddleware(array $options): void
    {
        try {
            // Lumen uses $app->middleware() instead of the HTTP Kernel
            $interceptor = new BotVersionInterceptor(self::$client, $options);
            app()->instance(BotVersionInterceptor::class, $interceptor);
            app()->middleware([BotVersionInterceptor::class]);
        } catch (\Exception $e) {
            // Silent
        }
    }

    private static function bootLumen(bool $debug): void
    {
        // Lumen does not have an app()->booted() hook, so we use a shutdown
        // function to do the static scan after the app has fully bootstrapped.
        register_shutdown_function(function () use ($debug) {
            // Only run once — guard with a static flag
            static $ran = false;
            if ($ran) return;
            $ran = true;

            try {
                $endpoints = BotVersionScanner::scanLumenRoutes();
                if (!empty($endpoints)) {
                    self::$client->registerEndpoints($endpoints);
                }
                $patterns = BotVersionScanner::scanFrontendRoutes();
                if (!empty($patterns)) {
                    self::$client->registerRoutePatterns($patterns);
                }
            } catch (\Exception $e) {
                if ($debug) error_log('[botversion:lumen] Scan failed: ' . $e->getMessage());
            }
        });
    }

    // =========================================================================
    // ── SYMFONY ───────────────────────────────────────────────────────────────
    // =========================================================================

    private static function bootSymfony(array $interceptorOptions, bool $debug): void
    {
        // Register a global event subscriber that:
        //   1. Intercepts every request/response pair (runtime scan)
        //   2. Does a one-time static route scan on the first request
        try {
            // We attach via PHP's output buffering + shutdown because Symfony's
            // event dispatcher requires a booted kernel reference we may not
            // have here. The Interceptor handles the per-request logic.
            // Users who have a kernel reference can pass it via options['symfony_kernel'].
            $kernel = self::$options['symfony_kernel'] ?? null;

            if ($kernel && method_exists($kernel, 'getContainer')) {
                // Full Symfony integration — attach via event dispatcher
                $container  = $kernel->getContainer();
                $dispatcher = $container->get('event_dispatcher');
                $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);
                $dispatcher->addListener(
                    'kernel.response',
                    [$interceptor, 'onKernelResponse']
                );
            }

            // Static scan — runs once via shutdown
            register_shutdown_function(function () use ($debug) {
                static $ran = false;
                if ($ran) return;
                $ran = true;

                try {
                    $endpoints = BotVersionScanner::scanSymfonyRoutes(
                        self::$options['symfony_kernel'] ?? null
                    );
                    if (!empty($endpoints)) {
                        self::$client->registerEndpoints($endpoints);
                    }
                    $patterns = BotVersionScanner::scanFrontendRoutes();
                    if (!empty($patterns)) {
                        self::$client->registerRoutePatterns($patterns);
                    }
                } catch (\Exception $e) {
                    if ($debug) error_log('[botversion:symfony] Scan failed: ' . $e->getMessage());
                }
            });

        } catch (\Exception $e) {
            if ($debug) error_log('[botversion:symfony] Boot failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ── SLIM 4 ────────────────────────────────────────────────────────────────
    // =========================================================================

    private static function bootSlim($slimApp, array $interceptorOptions, bool $debug): void
    {
        try {
            if ($slimApp && method_exists($slimApp, 'add')) {
                // Add as Slim middleware — receives ServerRequest + handler
                $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);
                $slimApp->add([$interceptor, 'processSlim']);
            }

            // Static scan via shutdown
            register_shutdown_function(function () use ($slimApp, $debug) {
                static $ran = false;
                if ($ran) return;
                $ran = true;

                try {
                    $endpoints = BotVersionScanner::scanSlimRoutes($slimApp);
                    if (!empty($endpoints)) {
                        self::$client->registerEndpoints($endpoints);
                    }
                    $patterns = BotVersionScanner::scanFrontendRoutes();
                    if (!empty($patterns)) {
                        self::$client->registerRoutePatterns($patterns);
                    }
                } catch (\Exception $e) {
                    if ($debug) error_log('[botversion:slim] Scan failed: ' . $e->getMessage());
                }
            });

        } catch (\Exception $e) {
            if ($debug) error_log('[botversion:slim] Boot failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ── CODEIGNITER 4 ─────────────────────────────────────────────────────────
    // =========================================================================

    private static function bootCodeIgniter(array $interceptorOptions, bool $debug): void
    {
        try {
            // CodeIgniter 4 uses Events for hooks.
            // We hook into post_controller to intercept after the controller runs.
            if (class_exists('\CodeIgniter\Events\Events')) {
                $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);

                \CodeIgniter\Events\Events::on('post_controller', function () use ($interceptor) {
                    $interceptor->handleCodeIgniter();
                });
            }

            // Static scan via shutdown
            register_shutdown_function(function () use ($debug) {
                static $ran = false;
                if ($ran) return;
                $ran = true;

                try {
                    $endpoints = BotVersionScanner::scanCodeIgniterRoutes();
                    if (!empty($endpoints)) {
                        self::$client->registerEndpoints($endpoints);
                    }
                    $patterns = BotVersionScanner::scanFrontendRoutes();
                    if (!empty($patterns)) {
                        self::$client->registerRoutePatterns($patterns);
                    }
                } catch (\Exception $e) {
                    if ($debug) error_log('[botversion:codeigniter] Scan failed: ' . $e->getMessage());
                }
            });

        } catch (\Exception $e) {
            if ($debug) error_log('[botversion:codeigniter] Boot failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ── FALLBACK (plain PHP / unknown framework) ──────────────────────────────
    // =========================================================================

    private static function bootFallback(array $interceptorOptions, bool $debug): void
    {
        // No static scan possible — we don't know how routes are defined.
        // We use output buffering + shutdown to capture the current request
        // and report it as a runtime-detected endpoint.
        register_shutdown_function(function () use ($interceptorOptions, $debug) {
            try {
                $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);
                $interceptor->handlePlainPhp();
            } catch (\Exception $e) {
                if ($debug) error_log('[botversion:fallback] Failed: ' . $e->getMessage());
            }
        });
    }
}