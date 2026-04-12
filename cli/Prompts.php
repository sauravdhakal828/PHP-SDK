<?php
// botversion-sdk-php/cli/Prompts.php

class BotVersionPrompts
{
    // ─── BASE HELPERS ─────────────────────────────────────────────────────────

    public static function ask(string $question): string
    {
        echo $question;
        $handle = fopen('php://stdin', 'r');
        $answer = trim(fgets($handle));
        fclose($handle);
        return $answer;
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

    // ─── SPECIFIC PROMPTS ─────────────────────────────────────────────────────

    public static function promptAuthLibrary(): array
    {
        $choices = [
            ['label' => 'Laravel Sanctum',          'value' => ['name' => 'sanctum',          'supported' => true]],
            ['label' => 'Laravel Passport',          'value' => ['name' => 'passport',          'supported' => true]],
            ['label' => 'Tymon JWT Auth',             'value' => ['name' => 'jwt-auth',          'supported' => true]],
            ['label' => 'Laravel Breeze / Jetstream', 'value' => ['name' => 'laravel-auth',      'supported' => true]],
            ['label' => 'Spatie Permission',          'value' => ['name' => 'spatie-permission', 'supported' => true]],
            ['label' => 'Other / Custom',             'value' => ['name' => 'custom',            'supported' => false]],
            ['label' => 'No auth',                    'value' => ['name' => null,                'supported' => false]],
        ];

        $choice = self::askChoice(
            "We couldn't detect your auth library. Which one are you using?",
            $choices
        );

        return $choice['value'];
    }

    public static function promptForce(string $conflictFile): bool
    {
        echo "\n  ⚠️  File already exists: $conflictFile\n";
        return self::confirm("  Overwrite it? (a backup will be created)", false);
    }
}