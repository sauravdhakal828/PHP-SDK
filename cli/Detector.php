<?php
// botversion-sdk-php/cli/Detector.php

class BotVersionDetector
{
    // ─── MAIN DETECT ─────────────────────────────────────────────────────────

    public static function detect(string $cwd): array
    {
        $composer  = self::readComposerJson($cwd);
        $framework = self::detectFramework($cwd, $composer);
        $auth      = self::detectAuth($composer);

        $result = [
            'cwd'              => $cwd,
            'composer'         => $composer,
            'framework'        => $framework,
            'auth'             => $auth,
            'alreadyInitialized' => self::detectExistingBotVersion($cwd),
        ];

        return $result;
    }

    // ─── COMPOSER.JSON ────────────────────────────────────────────────────────

    public static function readComposerJson(string $cwd): ?array
    {
        $path = $cwd . '/composer.json';
        if (!file_exists($path)) return null;
        try {
            return json_decode(file_get_contents($path), true);
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─── FRAMEWORK DETECTION ─────────────────────────────────────────────────

    public static function detectFramework(string $cwd, ?array $composer): ?string
    {
        // Check for artisan file — definitive Laravel indicator
        if (file_exists($cwd . '/artisan')) {
            return 'laravel';
        }

        // Check composer.json dependencies
        if ($composer) {
            $deps = array_merge(
                $composer['require'] ?? [],
                $composer['require-dev'] ?? []
            );

            if (isset($deps['laravel/framework'])) return 'laravel';
            if (isset($deps['slim/slim']))          return 'slim';       // unsupported
            if (isset($deps['symfony/framework-bundle'])) return 'symfony'; // unsupported
        }

        return null;
    }

    // ─── AUTH DETECTION ───────────────────────────────────────────────────────

    public static function detectAuth(?array $composer): array
    {
        if (!$composer) return ['name' => null, 'supported' => false];

        $deps = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? []
        );

        // Laravel Sanctum
        if (isset($deps['laravel/sanctum'])) {
            return ['name' => 'sanctum', 'supported' => true];
        }

        // Laravel Passport
        if (isset($deps['laravel/passport'])) {
            return ['name' => 'passport', 'supported' => true];
        }

        // Tymon JWT Auth
        if (isset($deps['tymon/jwt-auth'])) {
            return ['name' => 'jwt-auth', 'supported' => true];
        }

        // Spatie Permission (role-based — not a full auth, but common)
        if (isset($deps['spatie/laravel-permission'])) {
            return ['name' => 'spatie-permission', 'supported' => true];
        }

        // Laravel Breeze or Jetstream (uses built-in auth)
        if (isset($deps['laravel/breeze']) || isset($deps['laravel/jetstream'])) {
            return ['name' => 'laravel-auth', 'supported' => true];
        }

        // Fortify (headless auth)
        if (isset($deps['laravel/fortify'])) {
            return ['name' => 'fortify', 'supported' => true];
        }

        return ['name' => null, 'supported' => false];
    }

    // ─── EXISTING BOTVERSION DETECTION ───────────────────────────────────────

    public static function detectExistingBotVersion(string $cwd): bool
    {
        // Check AppServiceProvider
        $providerPath = $cwd . '/app/Providers/AppServiceProvider.php';
        if (file_exists($providerPath)) {
            $content = file_get_contents($providerPath);
            if (str_contains($content, 'BotVersion') || str_contains($content, 'botversion')) {
                return true;
            }
        }

        // Check routes/api.php
        $apiRoutesPath = $cwd . '/routes/api.php';
        if (file_exists($apiRoutesPath)) {
            $content = file_get_contents($apiRoutesPath);
            if (str_contains($content, 'BotVersion') || str_contains($content, 'botversion')) {
                return true;
            }
        }

        return false;
    }
}