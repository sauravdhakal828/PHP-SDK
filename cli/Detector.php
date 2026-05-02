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

                $result = $walk($fullPath, $depth + 1);
                if ($result) return $result;
            }

            return null;
        };

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

        if ($framework === 'angular') {
            $candidate = $dir . '/src/index.html';
            if (file_exists($candidate)) return ['file' => $candidate, 'type' => 'html'];
            return null;
        }

        if (in_array($framework, ['react-vite', 'vue', 'svelte', 'sveltekit', 'solid', 'preact'])) {
            if (file_exists($dir . '/index.html'))        return ['file' => $dir . '/index.html',        'type' => 'html'];
            if (file_exists($dir . '/public/index.html')) return ['file' => $dir . '/public/index.html', 'type' => 'html'];
            return null;
        }

        if ($framework === 'react-cra') {
            if (file_exists($dir . '/public/index.html')) return ['file' => $dir . '/public/index.html', 'type' => 'html'];
            if (file_exists($dir . '/index.html'))        return ['file' => $dir . '/index.html',        'type' => 'html'];
            return null;
        }

        $candidates = [
            'index.html', 'public/index.html',
            'static/index.html', 'src/index.html', 'www/index.html',
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

        $found = self::findHtmlFile($dir);
        if ($found) return ['file' => $found, 'type' => 'html'];

        return null;
    }

    // ─── FIND MAIN BLADE TEMPLATE ─────────────────────────────────────────────

    public static function findMainBladeTemplate(string $cwd): ?array
    {
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

    // ─── SCORE LARAVEL FILE ───────────────────────────────────────────────────────

    public static function scoreLaravelFile(string $content, string $filePath): int
    {
        $score    = 0;
        $filename = basename($filePath);

        // High confidence — registers as service provider
        if (preg_match('/class\s+\w+\s+extends\s+ServiceProvider/', $content))   $score += 10;
        // High confidence — boot() method present (entry point for init)
        if (preg_match('/public\s+function\s+boot\s*\(/', $content))             $score += 10;
        // High confidence — register() method present
        if (preg_match('/public\s+function\s+register\s*\(/', $content))         $score += 8;
        // High confidence — uses Application instance
        if (preg_match('/\$this->app/', $content))                                $score += 6;
        // Medium — references other providers
        if (preg_match('/protected\s+\$providers/', $content))                   $score += 5;
        // Low — just uses Laravel namespace
        if (str_contains($content, 'Illuminate\\'))                               $score += 1;

        // Penalties — test files
        if (preg_match('/(Test|Spec)\.php$/', $filename))                        $score -= 10;
        // Penalties — migration files
        if (str_contains($filePath, '/migrations/'))                             $score -= 10;
        // Penalties — model files (not entry points)
        if (preg_match('/extends\s+Model/', $content))                           $score -= 8;
        // Penalties — controller files
        if (preg_match('/extends\s+Controller/', $content))                      $score -= 8;
        // Penalties — middleware files
        if (preg_match('/public\s+function\s+handle\s*\(\s*Request/', $content)) $score -= 6;

        // Filename bonus
        if ($filename === 'AppServiceProvider.php')                              $score += 5;
        if (str_ends_with($filename, 'ServiceProvider.php'))                     $score += 3;

        return $score;
    }

    // ─── PARSE ENTRY FROM CONFIG FILES ───────────────────────────────────────────

    public static function parseEntryFromConfigFiles(string $cwd): ?string
    {
        /**
         * Extracts the likely service provider from Procfile or Dockerfile.
         * Covers patterns like:
         *   Procfile:   web: php artisan serve
         *   Dockerfile: CMD ["php", "artisan", "serve"]
         *   Dockerfile: ENTRYPOINT ["php", "-S", "0.0.0.0:8000", "-t", "public"]
         *
        * For Laravel, if we find artisan being used, we can confidently
         * return AppServiceProvider as the entry point.
         */

        $laravelSignals = ['artisan', 'laravel', 'php-fpm'];

        // ── 1. Check Procfile ─────────────────────────────────────────────────────
        $procfilePath = $cwd . '/Procfile';
        if (file_exists($procfilePath)) {
            $lines = explode("\n", file_get_contents($procfilePath));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line || str_starts_with($line, '#')) continue;

                foreach ($laravelSignals as $signal) {
                    if (str_contains($line, $signal)) {
                        // Confirmed Laravel — return standard provider path
                        $providerPath = $cwd . '/app/Providers/AppServiceProvider.php';
                        if (file_exists($providerPath)) return $providerPath;
                    }
                }
            }
        }

        // ── 2. Check Dockerfile ───────────────────────────────────────────────────
        $dockerfilePath = $cwd . '/Dockerfile';
        if (file_exists($dockerfilePath)) {
            $lines = explode("\n", file_get_contents($dockerfilePath));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line || str_starts_with($line, '#')) continue;

                foreach ($laravelSignals as $signal) {
                    if (str_contains($line, $signal)) {
                        $providerPath = $cwd . '/app/Providers/AppServiceProvider.php';
                        if (file_exists($providerPath)) return $providerPath;
                    }
                }
            }
        }

        return null;
    }

    // ─── DETECT SERVICE PROVIDER ──────────────────────────────────────────────────

    public static function detectServiceProvider(string $cwd): ?string
    {
        /**
         * Finds the best service provider file to inject into.
         * Uses scoring instead of first-match so it works for:
         * - Standard AppServiceProvider
         * - Custom named providers (e.g. BootServiceProvider)
         * - Providers in non-standard locations
         */
        $skipDirs = array_merge(self::$skipDirs, [
            'tests', 'test', 'migrations', 'database',
            'storage', 'bootstrap', 'public', 'resources',
            'lang', 'config', 'routes',
        ]);
  
        $scoredCandidates = [];

        $walk = function (string $directory, int $depth) use (&$walk, &$scoredCandidates, $skipDirs): void {
            if ($depth > 4) return;

            $entries = @scandir($directory);
            if (!$entries) return;

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (in_array($entry, $skipDirs))       continue;
                if (str_starts_with($entry, '.'))      continue;

                $fullPath = $directory . '/' . $entry;

                if (is_dir($fullPath)) {
                    $walk($fullPath, $depth + 1);
                } elseif (str_ends_with($entry, '.php')) {
                    $content = @file_get_contents($fullPath);
                    if (!$content) continue;

                    $score = self::scoreLaravelFile($content, $fullPath);
                    if ($score > 0) {
                        $scoredCandidates[] = ['score' => $score, 'path' => $fullPath];
                    }
                }
            }
        };

        $walk($cwd, 0);

        if (empty($scoredCandidates)) {
            // Nothing found by scoring — try Procfile/Dockerfile
            return self::parseEntryFromConfigFiles($cwd);
        }

        // Sort by score descending
        usort($scoredCandidates, fn($a, $b) => $b['score'] - $a['score']);
        $best = $scoredCandidates[0];

        // If best score is weak, cross-check with Procfile/Dockerfile
        if ($best['score'] <= 3) {
            $configEntry = self::parseEntryFromConfigFiles($cwd);
            if ($configEntry) return $configEntry;
        }

        return $best['path'];
    }

    // ─── MAIN DETECT ──────────────────────────────────────────────────────────

    public static function detect(string $cwd): array
    {
        $composer  = self::readComposerJson($cwd);
        $framework = self::detectFramework($cwd, $composer);

        // ── If no framework found in root, scan common backend folders ────────
        $backendRoot = $cwd;
        if (!$framework) {
            $backendDirs = ['backend', 'api', 'server', 'services', 'app'];
            foreach ($backendDirs as $dir) {
                $candidatePath = $cwd . '/' . $dir;
                if (!is_dir($candidatePath)) continue;

                $candidateComposer  = self::readComposerJson($candidatePath);
                $candidateFramework = self::detectFramework($candidatePath, $candidateComposer);

                if ($candidateFramework) {
                    $backendRoot = $candidatePath;
                    $composer    = $candidateComposer;
                    $framework   = $candidateFramework;
                    break;
                }
            }
        }
  
        $frontendMainFile = null;
        $frontendWarnOnly = false;

        // Scan for a separate frontend folder (e.g. /frontend, /client)
        $frontendFolder = self::scanForFrontendFolder($cwd);

        if ($frontendFolder) {
            $frontendMainFile = self::findMainFrontendFile(
                $frontendFolder['dir'],
                $frontendFolder['pkg']
            );
        }

        // Fallback — look for a Blade template inside Laravel
        if (!$frontendMainFile) {
            $bladeResult = self::findMainBladeTemplate($backendRoot);
            if ($bladeResult) {
                $frontendMainFile = $bladeResult;
                if (!empty($bladeResult['isWelcomeOnly'])) {
                    $frontendWarnOnly = true;
                }
            }
        }

        return [
            'cwd'                => $cwd,
            'backendRoot'        => $backendRoot,
            'composer'           => $composer,
            'framework'          => $framework,
            'frontendMainFile'   => $frontendMainFile,
            'frontendWarnOnly'   => $frontendWarnOnly,
            'frontendFolder'     => $frontendFolder,
            'alreadyInitialized' => self::detectExistingBotVersion($backendRoot),
            'serviceProvider'    => self::detectServiceProvider($backendRoot),
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
        if (file_exists($cwd . '/artisan')) {
            return 'laravel';
        }

        if ($composer) {
            $deps = array_merge(
                $composer['require'] ?? [],
                $composer['require-dev'] ?? []
            );

            if (isset($deps['laravel/framework']))         return 'laravel';
            if (isset($deps['slim/slim']))                 return 'slim';      // unsupported
            if (isset($deps['symfony/framework-bundle']))  return 'symfony';   // unsupported
        }

        return null;
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