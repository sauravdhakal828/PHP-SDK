#!/usr/bin/env php
<?php
// botversion-sdk-php/bin/botversion-init.php

require_once __DIR__ . '/../cli/Detector.php';
require_once __DIR__ . '/../cli/Generator.php';
require_once __DIR__ . '/../cli/Writer.php';
require_once __DIR__ . '/../cli/Prompts.php';

// ─── COLORS ──────────────────────────────────────────────────────────────────

function colorize(string $text, string $color): string
{
    $colors = [
        'reset'  => "\x1b[0m",
        'bold'   => "\x1b[1m",
        'green'  => "\x1b[32m",
        'yellow' => "\x1b[33m",
        'red'    => "\x1b[31m",
        'cyan'   => "\x1b[36m",
        'gray'   => "\x1b[90m",
        'white'  => "\x1b[37m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function cliInfo(string $msg): void  { echo colorize("  ℹ", 'cyan') . "  $msg\n"; }
function cliSuccess(string $msg): void { echo colorize("  ✔", 'green') . "  $msg\n"; }
function cliWarn(string $msg): void  { echo colorize("  ⚠", 'yellow') . "  $msg\n"; }
function cliError(string $msg): void { echo colorize("  ✖", 'red') . "  $msg\n"; }
function cliStep(string $msg): void  { echo "\n" . colorize("  → $msg", 'bold') . "\n"; }

// ─── PARSE ARGS ───────────────────────────────────────────────────────────────

function parseArgs(array $argv): array
{
    $args = ['key' => null, 'force' => false, 'cwd' => getcwd()];

    for ($i = 0; $i < count($argv); $i++) {
        if ($argv[$i] === '--key' && isset($argv[$i + 1])) {
            $args['key'] = $argv[$i + 1];
            $i++;
        } elseif ($argv[$i] === '--force') {
            $args['force'] = true;
        } elseif ($argv[$i] === '--cwd' && isset($argv[$i + 1])) {
            $args['cwd'] = realpath($argv[$i + 1]) ?: $argv[$i + 1];
            $i++;
        }
    }

    return $args;
}

// ─── BANNER ───────────────────────────────────────────────────────────────────

function printBanner(): void
{
    echo "\n";
    echo colorize("  ╔══════════════════════════════════════╗", 'cyan') . "\n";
    echo colorize("  ║       BotVersion SDK Setup CLI       ║", 'cyan') . "\n";
    echo colorize("  ╚══════════════════════════════════════╝", 'cyan') . "\n";
    echo "\n";
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────

function main(): void
{
    global $argv;
    $args = parseArgs(array_slice($argv, 1));

    printBanner();

    // ── Validate API key ──────────────────────────────────────────────────────
    if (!$args['key']) {
        cliError("API key is required.");
        echo "\n  Usage: php vendor/bin/botversion-init --key YOUR_WORKSPACE_KEY\n\n";
        echo "  Get your key from: https://app.botversion.com/settings\n\n";
        exit(1);
    }

    $cwd     = $args['cwd'];
    $changes = ['modified' => [], 'created' => [], 'backups' => [], 'manual' => []];

    // ── Detect environment ────────────────────────────────────────────────────
    cliStep("Scanning your project...");

    $detected = BotVersionDetector::detect($cwd);

    // ── Check already initialized ─────────────────────────────────────────────
    if ($detected['alreadyInitialized'] && !$args['force']) {
        cliWarn("BotVersion SDK is already initialized in this project.");
        echo "\n  To reinitialize, run with --force flag:\n";
        echo "  php vendor/bin/botversion-init --key {$args['key']} --force\n\n";
        exit(0);
    }

    // ── Framework check ───────────────────────────────────────────────────────
    cliStep("Detecting framework...");

    if (!$detected['framework']) {
        cliError("Could not detect a supported framework.");
        echo "\n  Supported: Laravel\n";
        echo "  Make sure you have a composer.json and artisan file.\n\n";
        exit(1);
    }

    cliSuccess("Framework: {$detected['framework']}");

    // ── Auth detection ────────────────────────────────────────────────────────
    cliStep("Detecting auth library...");

    $auth = $detected['auth'];

    if (!$auth['name']) {
        cliWarn("No auth library detected automatically.");
        $auth             = BotVersionPrompts::promptAuthLibrary();
        $detected['auth'] = $auth;
    } else {
        cliSuccess("Auth: {$auth['name']}");
    }

    // ── Setup Laravel ─────────────────────────────────────────────────────────
    if ($detected['framework'] === 'laravel') {
        setupLaravel($detected, $args, $changes, $cwd);
    }

    // ── Write API key to .env ─────────────────────────────────────────────────
    $envPath    = $cwd . '/.env';
    $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
    $envLine    = 'BOTVERSION_API_KEY=' . $args['key'];

    if (!str_contains($envContent, 'BOTVERSION_API_KEY')) {
        $write = BotVersionPrompts::confirm("Add BOTVERSION_API_KEY to .env?", true);

        if ($write) {
            $addition = "\n\n# BotVersion API key\n" . $envLine . "\n";
            file_put_contents($envPath, rtrim($envContent) . $addition);
            cliSuccess("Added BOTVERSION_API_KEY to .env");
            $changes['modified'][] = '.env';
        } else {
            cliWarn("Skipped — add this manually to your .env:");
            echo "\n    # BotVersion API key\n    $envLine\n\n";
            $changes['manual'][] = "Add to your .env:\n\n    # BotVersion API key\n    $envLine";
        }
    } else {
        cliInfo("BOTVERSION_API_KEY already exists in .env — skipping.");
    }

    // ── Print summary ─────────────────────────────────────────────────────────
    echo BotVersionWriter::writeSummary($changes);
}

// ─── LARAVEL SETUP ────────────────────────────────────────────────────────────

function setupLaravel(array $detected, array $args, array &$changes, string $cwd): void
{
    cliStep("Setting up Laravel...");

    // ── 1. Inject into AppServiceProvider ────────────────────────────────────
    $providerPath = $cwd . '/app/Providers/AppServiceProvider.php';

    if (!file_exists($providerPath)) {
        cliWarn("Could not find AppServiceProvider.php automatically.");
        $manualPath   = BotVersionPrompts::promptFilePath("Enter path to your ServiceProvider (e.g. app/Providers/AppServiceProvider.php): ");
        $providerPath = $cwd . '/' . ltrim($manualPath, '/');
    }

    if (file_exists($providerPath)) {
        $initCode = BotVersionGenerator::generateLaravelServiceProviderCode();
        $result   = BotVersionWriter::injectIntoServiceProvider($providerPath, $initCode, $args['force']);

        if ($result['success']) {
            cliSuccess("Injected BotVersion::init() into " . basename($providerPath));
            $changes['modified'][] = 'app/Providers/AppServiceProvider.php';
            if (!empty($result['backup'])) $changes['backups'][] = $result['backup'];
        } elseif ($result['reason'] === 'already_exists') {
            cliWarn("BotVersion already found in ServiceProvider — skipping.");
        } else {
            cliWarn("Could not auto-inject. Add this manually to your AppServiceProvider::boot():");
            echo "\n" . $initCode . "\n";
            $changes['manual'][] = "Add to AppServiceProvider::boot():\n\n" . $initCode;
        }
    } else {
        cliError("ServiceProvider not found: $providerPath");
        $initCode = BotVersionGenerator::generateLaravelServiceProviderCode();
        $changes['manual'][] = "Add to your AppServiceProvider::boot():\n\n" . $initCode;
    }

    // ── 2. Create chat route in routes/api.php ────────────────────────────────
    $apiRoutesPath = $cwd . '/routes/api.php';

    if (!file_exists($apiRoutesPath)) {
        cliWarn("Could not find routes/api.php — creating it.");
        file_put_contents($apiRoutesPath, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
        $changes['created'][] = 'routes/api.php';
    }

    $chatRouteCode = BotVersionGenerator::generateLaravelChatRoute();
    $result        = BotVersionWriter::injectChatRoute($apiRoutesPath, $chatRouteCode, $args['force']);

    if ($result['success']) {
        cliSuccess("Added BotVersion chat route to routes/api.php");
        $changes['modified'][] = 'routes/api.php';
    } elseif ($result['reason'] === 'already_exists') {
        cliWarn("BotVersion chat route already exists in routes/api.php — skipping.");
    }
}

// ─── RUN ──────────────────────────────────────────────────────────────────────

try {
    main();
} catch (Throwable $e) {
    cliError("Unexpected error: " . $e->getMessage());
    if (getenv('DEBUG')) {
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}