<?php
// botversion-sdk-php/cli/Writer.php

class BotVersionWriter
{
    // ─── BACKUP A FILE ────────────────────────────────────────────────────────

    public static function backupFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) return null;
        $backupPath = $filePath . '.backup-before-botversion';
        copy($filePath, $backupPath);
        return $backupPath;
    }

    // ─── INJECT INTO AppServiceProvider::boot() ──────────────────────────────

    public static function injectIntoServiceProvider(string $filePath, string $codeToInject, bool $force = false): array
    {
        $content = file_get_contents($filePath);

        // Idempotency check — never inject twice, even with --force
        if (str_contains($content, 'BotVersion') || str_contains($content, 'botversion')) {
            return ['success' => false, 'reason' => 'already_exists'];
        }

        // Find the boot() method using brace counting instead of regex
        // This correctly handles nested closures, arrays, etc.
        $bootStart = self::findBootMethodStart($content);

        if ($bootStart === -1) {
            return ['success' => false, 'reason' => 'boot_not_found'];
        }

        // Find the opening brace of boot()
        $bracePos = strpos($content, '{', $bootStart);
        if ($bracePos === false) {
            return ['success' => false, 'reason' => 'boot_not_found'];
        }

        // Count braces to find the correct closing brace of boot()
        $depth        = 1;
        $pos          = $bracePos + 1;
        $length       = strlen($content);
        $inString     = false;
        $stringChar   = '';

        while ($pos < $length && $depth > 0) {
            $char = $content[$pos];

            // Track strings to avoid counting braces inside them
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString   = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                $inString = false;
            } elseif (!$inString) {
                if ($char === '{') $depth++;
                if ($char === '}') $depth--;
            }

            if ($depth > 0) $pos++;
        }

        // $pos now points to the closing } of boot()
        // Inject our code just before it
        $indented      = self::indentCode($codeToInject, '        ');
        $injection     = "\n" . $indented . "\n    ";

        $newContent = substr($content, 0, $bracePos + 1)
            . $injection
            . substr($content, $bracePos + 1, $pos - $bracePos - 1)
            . substr($content, $pos);

        $backup = self::backupFile($filePath);
        file_put_contents($filePath, $newContent);
        return ['success' => true, 'backup' => $backup];
    }

    // ─── FIND boot() METHOD START POSITION ───────────────────────────────────
    // Returns the position of "public function boot" in the file content,
    // or -1 if not found.

    private static function findBootMethodStart(string $content): int
    {
        // Match both: public function boot(): void and public function boot()
        $pattern = '/public\s+function\s+boot\s*\(\s*\)/';
        if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0][1];
        }
        return -1;
    }

    // ─── INJECT CHAT ROUTE INTO routes/api.php ───────────────────────────────

    public static function injectChatRoute(string $filePath, string $codeToInject, bool $force = false): array
    {
        $content = file_get_contents($filePath);

        // Idempotency check — never inject twice, even with --force
        if (str_contains($content, 'botversion/chat') || str_contains($content, 'BotVersion::chat')) {
            return ['success' => false, 'reason' => 'already_exists'];
        }

        // Backup before writing
        $backup     = self::backupFile($filePath);
        $newContent = rtrim($content) . "\n" . $codeToInject . "\n";
        file_put_contents($filePath, $newContent);
        return ['success' => true, 'backup' => $backup];
    }

    // ─── INJECT SCRIPT TAG INTO FRONTEND FILE ────────────────────────────────

    public static function injectScriptTag(string $filePath, string $fileType, string $scriptTag, bool $force = false): array
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'reason' => 'file_not_found'];
        }

        $content = file_get_contents($filePath);

        // Idempotency check
        if (str_contains($content, 'botversion-loader')) {
            if (!$force) {
                return ['success' => false, 'reason' => 'already_exists'];
            }
        }

        $backup = self::backupFile($filePath);

        // ── HTML or Blade file — inject before the LAST </body> ──────────────
        // Using strrpos (last occurrence) to avoid injecting into
        // comments or strings that contain </body>
        if ($fileType === 'html' || $fileType === 'blade') {
            $pos = strrpos($content, '</body>');

            if ($pos === false) {
                return ['success' => false, 'reason' => 'no_body_tag'];
            }

            $newContent = substr($content, 0, $pos)
                . "  {$scriptTag}\n"
                . substr($content, $pos);

            file_put_contents($filePath, $newContent);
            return ['success' => true, 'backup' => $backup];
        }

        return ['success' => false, 'reason' => 'unsupported_file_type'];
    }

    // ─── CREATE A NEW FILE ────────────────────────────────────────────────────

    public static function createFile(string $filePath, string $content, bool $force = false): array
    {
        if (file_exists($filePath) && !$force) {
            return ['success' => false, 'reason' => 'already_exists', 'path' => $filePath];
        }

        if (file_exists($filePath) && $force) {
            self::backupFile($filePath);
        }

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $content);
        return ['success' => true, 'path' => $filePath];
    }

    // ─── WRITE SUMMARY ────────────────────────────────────────────────────────

    public static function writeSummary(array $changes): string
    {
        $lines = [
            "",
            "┌─────────────────────────────────────────────┐",
            "│         BotVersion Setup Complete!          │",
            "└─────────────────────────────────────────────┘",
            "",
        ];

        if (!empty($changes['modified'])) {
            $lines[] = "  Modified files:";
            foreach ($changes['modified'] as $f) {
                $lines[] = "    [modified]  $f";
            }
            $lines[] = "";
        }

        if (!empty($changes['created'])) {
            $lines[] = "  Created files:";
            foreach ($changes['created'] as $f) {
                $lines[] = "    [created]  $f";
            }
            $lines[] = "";
        }

        if (!empty($changes['backups'])) {
            $lines[] = "  Backups created:";
            foreach ($changes['backups'] as $f) {
                $lines[] = "    [backup]  $f";
            }
            $lines[] = "";
        }

        if (!empty($changes['manual'])) {
            $lines[] = "  [!] Manual steps needed:";
            foreach ($changes['manual'] as $m) {
                $lines[] = "    → $m";
            }
            $lines[] = "";
        }

        $lines[] = "  Next: Restart your server and test the chat widget.";
        $lines[] = "  Docs: https://docs.botversion.com";
        $lines[] = "";

        return implode("\n", $lines);
    }

    // ─── HELPER: indent every line of a code block ───────────────────────────

    private static function indentCode(string $code, string $indent): string
    {
        $lines = explode("\n", $code);
        return implode("\n", array_map(
            fn($line) => $line === '' ? '' : $indent . $line,
            $lines
        ));
    }
}