<?php
// botversion-sdk-php/cli/Detector.php

class BotVersionDetector
{
    // ─── SKIP DIRS ────────────────────────────────────────────────────────────

    private static array $skipDirs = [
        'node_modules', '.git', '.next', 'dist', 'build',
        '.cache', 'vendor', '__pycache__', 'coverage', 'out',
    ];

    // ─── SCAN FOR SEPARATE FRONTEND FOLDER ───────────────────────────────────
    // Only looks in subdirectories — never returns the root itself,
    // because Laravel's own package.json would be a false positive.

    public static function scanForFrontendFolder(string $cwd): ?array
    {
        $walk = function (string $dir, int $depth) use (&$walk, $cwd): ?array {
            if ($depth > 4) return null;

            $entries = @scandir($dir);
            if (!$entries) return null;

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (in_array($entry, self::$skipDirs)) continue;

                $fullPath = $dir . '/' . $entry;
                if (!is_dir($fullPath)) continue;

                // Check if this subdirectory has a frontend package.json
                $pkgPath = $fullPath . '/package.json';
                if (file_exists($pkgPath)) {
                    try {
                        $pkg = json_decode(file_get_contents($pkgPath), true);
                        if ($pkg && self::isFrontendPackage($pkg)) {
                            return ['dir' => $fullPath, 'pkg' => $pkg];
                        }
                    } catch (Throwable $e) {
                        // ignore and keep walking
                    }
                }

                // Recurse deeper
                $result = $walk($fullPath, $depth + 1);
                if ($result) return $result;
            }

            return null;
        };

        // Start walking from $cwd but never treat $cwd itself as a result
        return $walk($cwd, 0);
    }

    // ─── CHECK IF PACKAGE.JSON IS FRONTEND ───────────────────────────────────

    public static function isFrontendPackage(array $pkg): bool
    {
        $deps = array_merge(
            $pkg['dependencies'] ?? [],
            $pkg['devDependencies'] ?? []
        );

        $frontendMarkers = [
            'react', 'react-dom', 'vue', '@angular/core',
            'svelte', 'solid-js', 'preact', 'next',
        ];

        foreach ($frontendMarkers as $marker) {
            if (isset($deps[$marker])) return true;
        }

        return false;
    }

    // ─── DETECT FRONTEND FRAMEWORK ────────────────────────────────────────────

    public static function detectFrontendFramework(array $pkg): ?string
    {
        $deps = array_merge(
            $pkg['dependencies'] ?? [],
            $pkg['devDependencies'] ?? []
        );

        if (isset($deps['next']))             return 'next';
        if (isset($deps['@sveltejs/kit']))    return 'sveltekit';
        if (isset($deps['svelte']))           return 'svelte';
        if (isset($deps['@angular/core']))    return 'angular';
        if (isset($deps['vue']))              return 'vue';
        if (isset($deps['react-dom']) || isset($deps['react'])) {
            if (isset($deps['vite']) || isset($deps['@vitejs/plugin-react'])) return 'react-vite';
            return 'react-cra';
        }
        if (isset($deps['solid-js'])) return 'solid';
        if (isset($deps['preact']))   return 'preact';

        return null;
    }

    // ─── FIND MAIN FRONTEND FILE ──────────────────────────────────────────────

    public static function findMainFrontendFile(string $dir, array $pkg): ?array
    {
        $framework = self::detectFrontendFramework($pkg);

        // ── Angular ───────────────────────────────────────────────────────────
        if ($framework === 'angular') {
            $candidate = $dir . '/src/index.html';
            if (file_exists($candidate)) return ['file' => $candidate, 'type' => 'html'];
            return null;
        }

        // ── Vite-based ────────────────────────────────────────────────────────
        if (in_array($framework, ['react-vite', 'vue', 'svelte', 'sveltekit', 'solid', 'preact'])) {
            if (file_exists($dir . '/index.html'))        return ['file' => $dir . '/index.html',        'type' => 'html'];
            if (file_exists($dir . '/public/index.html')) return ['file' => $dir . '/public/index.html', 'type' => 'html'];
            return null;
        }

        // ── React CRA ─────────────────────────────────────────────────────────
        if ($framework === 'react-cra') {
            if (file_exists($dir . '/public/index.html')) return ['file' => $dir . '/public/index.html', 'type' => 'html'];
            if (file_exists($dir . '/index.html'))        return ['file' => $dir . '/index.html',        'type' => 'html'];
            return null;
        }

        // ── Unknown — scan common locations ───────────────────────────────────
        $candidates = [
            'index.html',
            'public/index.html',
            'static/index.html',
            'src/index.html',
            'www/index.html',
        ];

        foreach ($candidates as $candidate) {
            $fullPath = $dir . '/' . $candidate;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                if (str_contains($content, '<body') || str_contains($content, '<html')) {
                    return ['file' => $fullPath, 'type' => 'html'];
                }
            }
        }

        // ── Last resort — deep scan ───────────────────────────────────────────
        $found = self::findHtmlFile($dir);
        if ($found) return ['file' => $found, 'type' => 'html'];

        return null;
    }

    // ─── FIND MAIN BLADE TEMPLATE ─────────────────────────────────────────────
    // Looks for the main Laravel Blade LAYOUT file — not page views.
    // The script tag must be in the layout so it appears on every page.

    public static function findMainBladeTemplate(string $cwd): ?array
    {
        // Priority: layout files first (script tag must cover all pages)
        // welcome.blade.php is last resort — warn the user it only covers one page
        $candidates = [
            'resources/views/layouts/app.blade.php',
            'resources/views/layouts/master.blade.php',
            'resources/views/layouts/main.blade.php',
            'resources/views/app.blade.php',
            'resources/views/base.blade.php',
        ];

        foreach ($candidates as $candidate) {
            $fullPath = $cwd . '/' . $candidate;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                if (str_contains($content, '<body') || str_contains($content, '<html')) {
                    return ['file' => $fullPath, 'type' => 'html'];
                }
            }
        }

        // welcome.blade.php is a single page — only use if nothing else found,
        // and the caller should warn the user about it.
        $welcome = $cwd . '/resources/views/welcome.blade.php';
        if (file_exists($welcome)) {
            $content = file_get_contents($welcome);
            if (str_contains($content, '<body') || str_contains($content, '<html')) {
                return ['file' => $welcome, 'type' => 'html', 'isWelcomeOnly' => true];
            }
        }

        return null;
    }

    // ─── DEEP SCAN FOR HTML FILE ──────────────────────────────────────────────

    public static function findHtmlFile(string $dir): ?string
    {
        $walk = function (string $currentDir, int $depth) use (&$walk): ?string {
            if ($depth > 3) return null;

            $entries = @scandir($currentDir);
            if (!$entries) return null;

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (in_array($entry, self::$skipDirs)) continue;

                $fullPath = $currentDir . '/' . $entry;

                if (is_dir($fullPath)) {
                    $result = $walk($fullPath, $depth + 1);
                    if ($result) return $result;
                } elseif (str_ends_with($entry, '.html')) {
                    $content = @file_get_contents($fullPath);
                    if ($content && (str_contains($content, '<body') || str_contains($content, '<html'))) {
                        return $fullPath;
                    }
                }
            }

            return null;
        };

        return $walk($dir, 0);
    }

    // ─── MAIN DETECT ──────────────────────────────────────────────────────────

    public static function detect(string $cwd): array
    {
        $composer  = self::readComposerJson($cwd);
        $framework = self::detectFramework($cwd, $composer);
        $auth      = self::detectAuth($composer);

        // ── Frontend detection (3-step fallback chain) ────────────────────────

        $frontendMainFile  = null;
        $frontendWarnOnly  = false;

        // Step 1: look for a SEPARATE frontend folder with its own package.json
        // (e.g. a React app living alongside the Laravel backend)
        $frontendFolder = self::scanForFrontendFolder($cwd);
        if ($frontendFolder) {
            $frontendMainFile = self::findMainFrontendFile(
                $frontendFolder['dir'],
                $frontendFolder['pkg']
            );
        }

        // Step 2: look for a Blade LAYOUT template in resources/views/layouts/
        // This is the correct target for Laravel — covers all pages via @extends
        if (!$frontendMainFile) {
            $bladeResult = self::findMainBladeTemplate($cwd);
            if ($bladeResult) {
                $frontendMainFile = $bladeResult;
                // Flag if we only found welcome.blade.php — caller should warn
                if (!empty($bladeResult['isWelcomeOnly'])) {
                    $frontendWarnOnly = true;
                }
            }
        }

        // Step 3: DO NOT fall back to plain HTML scan for Laravel projects.
        // public/index.html in Laravel is a Vite stub, not the real frontend.
        // If we still have nothing, leave it null and let the caller handle it.

        return [
            'cwd'                  => $cwd,
            'composer'             => $composer,
            'framework'            => $framework,
            'auth'                 => $auth,
            'frontendMainFile'     => $frontendMainFile,
            'frontendWarnOnly'     => $frontendWarnOnly,
            'alreadyInitialized'   => self::detectExistingBotVersion($cwd),
        ];
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
        // artisan file is the definitive Laravel indicator
        if (file_exists($cwd . '/artisan')) {
            return 'laravel';
        }

        if ($composer) {
            $deps = array_merge(
                $composer['require'] ?? [],
                $composer['require-dev'] ?? []
            );

            if (isset($deps['laravel/framework']))        return 'laravel';
            if (isset($deps['slim/slim']))                return 'slim';      // unsupported
            if (isset($deps['symfony/framework-bundle'])) return 'symfony';   // unsupported
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

        if (isset($deps['laravel/sanctum']))   return ['name' => 'sanctum',          'supported' => true];
        if (isset($deps['laravel/passport']))  return ['name' => 'passport',          'supported' => true];
        if (isset($deps['tymon/jwt-auth']))    return ['name' => 'jwt-auth',          'supported' => true];
        if (isset($deps['laravel/breeze']) || isset($deps['laravel/jetstream']))
                                               return ['name' => 'laravel-auth',      'supported' => true];
        if (isset($deps['laravel/fortify']))   return ['name' => 'fortify',           'supported' => true];
        if (isset($deps['spatie/laravel-permission']))
                                               return ['name' => 'spatie-permission', 'supported' => true];

        return ['name' => null, 'supported' => false];
    }

    // ─── EXISTING BOTVERSION DETECTION ───────────────────────────────────────

    public static function detectExistingBotVersion(string $cwd): bool
    {
        $filesToCheck = [
            $cwd . '/app/Providers/AppServiceProvider.php',
            $cwd . '/routes/api.php',
            $cwd . '/app/Providers/BotVersionServiceProvider.php',
        ];

        foreach ($filesToCheck as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if (str_contains($content, 'BotVersion') || str_contains($content, 'botversion')) {
                    return true;
                }
            }
        }

        return false;
    }
}