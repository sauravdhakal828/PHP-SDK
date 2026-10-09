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
     * PHP SDKs only ever run on a backend server — there's no such thing
     * as a PHP frontend project. So unlike the JS SDK, this is a simple
     * two-way classification: either a supported backend framework was
     * detected, or it wasn't.
     */
    private static function classifyInstallation(?string $framework): string
    {
        return $framework ? 'backend-only' : 'unknown';
    }

    /**
     * Run a full scan (backend endpoints + frontend routes) and report results.
     * This used to run automatically on boot for each framework. Now it only
     * runs when the BotVersion dashboard's "Scan" button sends a request to
     * this SDK's scan-trigger endpoint (see Interceptor.php). This fixes scans
     * not happening on redeploys where there's no reliable "boot" moment to
     * hook into — and, for plain-PHP/PHP-FPM style deployments, no persistent
     * process at all.
     */
    private static function runFullScan(string $framework, bool $debug, $slimApp = null): array
    {
        $result = [
            'endpointCount'    => 0,
            'routeCount'       => 0,
            'projectType'      => null,
            'detectedBackend'  => $framework,
            'detectedFrontend' => null,
            'sdkLanguage'      => 'php',
        ];

        try {
            $endpoints = [];
            switch ($framework) {
                case 'laravel':     $endpoints = BotVersionScanner::scanLaravelRoutes(); break;
                case 'lumen':       $endpoints = BotVersionScanner::scanLumenRoutes(); break;
                case 'symfony':     $endpoints = BotVersionScanner::scanSymfonyRoutes(self::$options['symfony_kernel'] ?? null); break;
                case 'slim':        $endpoints = BotVersionScanner::scanSlimRoutes($slimApp); break;
                case 'codeigniter': $endpoints = BotVersionScanner::scanCodeIgniterRoutes(); break;
            }

            $result['endpointCount'] = count($endpoints);

            if (!empty($endpoints)) {
                self::$client->registerEndpoints($endpoints);
            }
        } catch (\Exception $e) {
            if ($debug) error_log('[botversion] Scan failed: ' . $e->getMessage());
        }

        $result['detectedFrontend'] = null;
        $result['projectType']      = self::classifyInstallation($framework);

        return $result;
    }

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

            // Re-attach interceptor on framework reload
            $framework = self::detectFramework();
            $debug     = self::$options['debug'] ?? false;
            $slimApp   = self::$options['slim_app'] ?? null;

            $interceptorOptions = [
                'exclude'           => self::$options['exclude'] ?? [],
                'api_prefix'        => self::$options['api_prefix'] ?? null,
                'debug'             => $debug,
                'scan_secret'       => self::$client->getApiKey(),
                'on_scan_requested' => function () use ($framework, $debug, $slimApp) {
                    return self::runFullScan($framework, $debug, $slimApp);
                },
            ];

            switch ($framework) {
                case 'laravel':
                    self::attachLaravelMiddleware($interceptorOptions);
                    break;
                case 'lumen':
                    self::attachLumenMiddleware($interceptorOptions);
                    break;
                case 'symfony':
                    self::bootSymfony($interceptorOptions, $debug);
                    break;
                case 'slim':
                    self::bootSlim($slimApp, $interceptorOptions, $debug);
                    break;
                case 'codeigniter':
                    self::bootCodeIgniter($interceptorOptions, $debug);
                    break;
            }
            return;
        }

        if (empty($apiKey)) return;

        self::$initialized = true;
        self::$options     = $options;
        $debug             = $options['debug'] ?? false;

        self::$client = new BotVersionClient([
            'api_key'      => $apiKey,
            'platform_url' => $options['platform_url'] ?? 'https://console.botversion.com',
            'debug'        => $debug,
            'timeout'      => $options['timeout'] ?? 5,
        ]);

        $framework = self::detectFramework($options);
        $slimApp   = $options['slim_app'] ?? null;

        $interceptorOptions = [
            'exclude'           => $options['exclude'] ?? [],
            'api_prefix'        => $options['api_prefix'] ?? null,
            'debug'             => $debug,
            'scan_secret'       => $apiKey,
            'on_scan_requested' => function () use ($framework, $debug, $slimApp) {
                return self::runFullScan($framework, $debug, $slimApp);
            },
        ];

        // ── Attach interceptor per framework ──────────────────────────────────
        switch ($framework) {

            case 'laravel':
                self::attachLaravelMiddleware($interceptorOptions);
                break;

            case 'lumen':
                self::attachLumenMiddleware($interceptorOptions);
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

    // =========================================================================
    // ── SYMFONY ───────────────────────────────────────────────────────────────
    // =========================================================================

    private static function bootSymfony(array $interceptorOptions, bool $debug): void
    {
        // Register a global event subscriber that intercepts every
        // request/response pair for runtime detection, plus an early
        // kernel.request listener that can answer the on-demand scan
        // trigger from the BotVersion dashboard before routing even runs.
        try {
            // Users who have a kernel reference can pass it via options['symfony_kernel'].
            $kernel = self::$options['symfony_kernel'] ?? null;

            if ($kernel && method_exists($kernel, 'getContainer')) {
                $container  = $kernel->getContainer();
                $dispatcher = $container->get('event_dispatcher');
                $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);
                $dispatcher->addListener(
                    'kernel.request',
                    [$interceptor, 'onKernelRequest']
                );
                $dispatcher->addListener(
                    'kernel.response',
                    [$interceptor, 'onKernelResponse']
                );
            }
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
            // pre_system fires before routing — early enough to answer the
            // scan trigger. post_controller still handles runtime interception.
            if (class_exists('\CodeIgniter\Events\Events')) {
                $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);

                \CodeIgniter\Events\Events::on('pre_system', function () use ($interceptor) {
                    $interceptor->handleCodeIgniterScanCheck();
                });

                \CodeIgniter\Events\Events::on('post_controller', function () use ($interceptor) {
                    $interceptor->handleCodeIgniter();
                });
            }
        } catch (\Exception $e) {
            if ($debug) error_log('[botversion:codeigniter] Boot failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ── FALLBACK (plain PHP / unknown framework) ──────────────────────────────
    // =========================================================================

    private static function bootFallback(array $interceptorOptions, bool $debug): void
    {
        $interceptor = new BotVersionInterceptor(self::$client, $interceptorOptions);

        // Check for the scan trigger immediately — plain PHP has no
        // persistent process, so this is the only moment the SDK gets
        // to answer it. If matched, respond and stop here.
        try {
            if ($interceptor->checkAndHandleScanTrigger()) {
                exit;
            }
        } catch (\Exception $e) {
            if ($debug) error_log('[botversion:fallback] Scan check failed: ' . $e->getMessage());
        }

        // We use output buffering + shutdown to capture the current request
        // and report it as a runtime-detected endpoint.
        register_shutdown_function(function () use ($interceptor, $debug) {
            try {
                $interceptor->handlePlainPhp();
            } catch (\Exception $e) {
                if ($debug) error_log('[botversion:fallback] Failed: ' . $e->getMessage());
            }
        });
    }
}