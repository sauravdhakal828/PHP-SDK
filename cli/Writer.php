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

        // Already initialized check
        if (str_contains($content, 'BotVersion') || str_contains($content, 'botversion')) {
            if (!$force) {
                return ['success' => false, 'reason' => 'already_exists'];
            }
        }

        // Find the boot() method and inject inside it
        // Handles both empty boot() and boot() with existing content
        $pattern = '/(public\s+function\s+boot\s*\(\s*\)\s*:\s*void\s*\{)([\s\S]*?)(\n\s*\})/';

        if (!preg_match($pattern, $content)) {
            // Try without return type hint
            $pattern = '/(public\s+function\s+boot\s*\(\s*\)\s*\{)([\s\S]*?)(\n\s*\})/';
        }

        if (preg_match($pattern, $content)) {
            $indented = self::indentCode($codeToInject, '        ');
            $injection = "\n        " . $indented . "\n";

            $newContent = preg_replace_callback($pattern, function ($matches) use ($injection) {
                return $matches[1] . $injection . $matches[2] . $matches[3];
            }, $content, 1);

            $backup = self::backupFile($filePath);
            file_put_contents($filePath, $newContent);
            return ['success' => true, 'backup' => $backup];
        }

        return ['success' => false, 'reason' => 'boot_not_found'];
    }

    // ─── INJECT CHAT ROUTE INTO routes/api.php ───────────────────────────────

    public static function injectChatRoute(string $filePath, string $codeToInject, bool $force = false): array
    {
        $content = file_get_contents($filePath);

        if (str_contains($content, 'botversion/chat') || str_contains($content, 'BotVersion::chat')) {
            if (!$force) {
                return ['success' => false, 'reason' => 'already_exists'];
            }
        }

        // Append to end of file
        $newContent = rtrim($content) . "\n" . $codeToInject . "\n";
        file_put_contents($filePath, $newContent);
        return ['success' => true];
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
                $lines[] = "    ✏️  $f";
            }
            $lines[] = "";
        }

        if (!empty($changes['created'])) {
            $lines[] = "  Created files:";
            foreach ($changes['created'] as $f) {
                $lines[] = "    ✅  $f";
            }
            $lines[] = "";
        }

        if (!empty($changes['backups'])) {
            $lines[] = "  Backups created:";
            foreach ($changes['backups'] as $f) {
                $lines[] = "    💾  $f";
            }
            $lines[] = "";
        }

        if (!empty($changes['manual'])) {
            $lines[] = "  ⚠️  Manual steps needed:";
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

    // ─── HELPER: indent code block ────────────────────────────────────────────

    private static function indentCode(string $code, string $indent): string
    {
        $lines = explode("\n", $code);
        return implode("\n" . $indent, $lines);
    }
}