<?php
// botversion-sdk-php/cli/Prompts.php

class BotVersionPrompts
{
    // ─── STDIN HANDLE (opened once, reused throughout) ────────────────────────

    private static function stdin()
    {
        static $handle = null;
        if ($handle === null) {
            $handle = fopen('php://stdin', 'r');
        }
        return $handle;
    }

    // ─── BASE HELPERS ─────────────────────────────────────────────────────────

    public static function ask(string $question): string
    {
        echo $question;
        return trim(fgets(self::stdin()));
    }

    public static function askChoice(string $question, array $choices): array
    {
        echo "\n" . $question . "\n";
        foreach ($choices as $i => $choice) {
            echo "  " . ($i + 1) . ". " . $choice['label'] . "\n";
        }
        echo "\n";

        $count = count($choices);
        while (true) {
            $answer = self::ask("Enter number (1-$count): ");
            $num    = (int) $answer;
            if ($num >= 1 && $num <= $count) {
                return $choices[$num - 1];
            }
            echo "  Please enter a number between 1 and $count\n";
        }
    }

    public static function confirm(string $question, bool $defaultYes = true): bool
    {
        $hint   = $defaultYes ? '[Y/n]' : '[y/N]';
        $answer = self::ask("$question $hint: ");

        if ($answer === '') return $defaultYes;
        return strtolower($answer[0]) === 'y';
    }

    public static function promptFilePath(string $question): string
    {
        return self::ask($question);
    }

    public static function promptForce(string $conflictFile): bool
    {
        echo "\n  ⚠️  File already exists: $conflictFile\n";
        return self::confirm("  Overwrite it? (a backup will be created)", false);
    }

    // Config files that should never be appended to
    private static array $configFiles = [
        'config.php',
        'bootstrap.php',
        'kernel.php',
        'Kernel.php',
        'settings.php',
        'cors.php',
        'app.php',
    ];

    public static function promptMissingBootMethod(string $filePath): array
    {
        $fileName     = basename($filePath);
        $isConfigFile = in_array($fileName, self::$configFiles);

        echo "\n  ⚠  We couldn't find the boot() method in {$fileName}\n";

        if ($isConfigFile) {
            echo "\n  ❌  \"{$fileName}\" is a config file, not a service provider.\n";
            echo "      Appending server code here would break your project.\n";
            echo "  Options:\n";

            $choices = [
                ['label' => 'Enter the correct service provider file path manually', 'value' => 'manual_path'],
                ['label' => 'Skip — I\'ll add it manually',                          'value' => 'skip'],
            ];

            $choice = self::askChoice("How would you like to proceed?", $choices);

            if ($choice['value'] === 'manual_path') {
                $filePath = self::ask("  Enter file path: ");
                return ['action' => 'manual_path', 'filePath' => $filePath];
            }

            return ['action' => $choice['value']];
        }

        // Normal flow — not a config file
        echo "  Options:\n";
        $choices = [
            ['label' => 'Append to end of file',                       'value' => 'append'],
            ['label' => 'Enter the correct file path manually',        'value' => 'manual_path'],
            ['label' => 'Skip — I\'ll add it manually',                'value' => 'skip'],
        ];

        $choice = self::askChoice("How would you like to proceed?", $choices);

        if ($choice['value'] === 'manual_path') {
            $filePath = self::ask("  Enter file path: ");
            return ['action' => 'manual_path', 'filePath' => $filePath];
        }

        return ['action' => $choice['value']];
    }
}