<?php
// botversion-sdk-php/cli/Generator.php

class BotVersionGenerator
{
    // ─── SERVICE PROVIDER INIT CODE ──────────────────────────────────────────

    public static function generateLaravelServiceProviderCode(): string
    {
        return <<<PHP
// BotVersion AI Agent — auto-added by botversion-sdk init
\\BotVersion::init(env('BOTVERSION_API_KEY'), [
    'routes_dir' => base_path(),
]);
PHP;
    }

    // ─── SCRIPT TAG GENERATION ────────────────────────────────────────────────
    // data-proxy-url removed — widget now talks directly to platform

    public static function generateScriptTag(array $projectInfo): string
    {
        $cdnUrl    = $projectInfo['cdnUrl']    ?? '';
        $apiUrl    = $projectInfo['apiUrl']    ?? '';
        $projectId = $projectInfo['projectId'] ?? '';
        $publicKey = $projectInfo['publicKey'] ?? '';

        return <<<HTML
<script
  id="botversion-loader"
  src="{$cdnUrl}"
  data-api-url="{$apiUrl}"
  data-project-id="{$projectId}"
  data-public-key="{$publicKey}"
></script>
HTML;
    }

    // ─── MANUAL INSTRUCTIONS FOR UNSUPPORTED FRAMEWORKS ──────────────────────

    public static function generateManualInstructions(string $framework): string
    {
        $instructions = [
            'symfony' => "Symfony support is coming soon.\nSee: https://docs.botversion.com/symfony",
            'slim'    => "Slim support is coming soon.\nSee: https://docs.botversion.com/slim",
        ];

        return $instructions[$framework]
            ?? "This framework is not yet supported.\nVisit https://docs.botversion.com for manual setup.";
    }
}