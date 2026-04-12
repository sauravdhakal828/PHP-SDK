<?php
// botversion-sdk-php/cli/Generator.php

class BotVersionGenerator
{
    // ─── SERVICE PROVIDER INIT CODE ──────────────────────────────────────────

    public static function generateLaravelServiceProviderCode(): string
    {
        return <<<'PHP'
// BotVersion AI Agent — auto-added by botversion-sdk init
\BotVersion::init(env('BOTVERSION_API_KEY'), [
    // 'debug' => true,
    // Optional: override user context (by default SDK auto-detects from auth()->user())
    // 'get_user_context' => fn($request) => [
    //     'userId' => $request->user()?->id,
    //     'email'  => $request->user()?->email,
    // ],
]);
PHP;
    }

    // ─── CHAT ROUTE CODE ─────────────────────────────────────────────────────

    public static function generateLaravelChatRoute(): string
    {
        return <<<'PHP'

// BotVersion AI Agent chat endpoint — auto-added by botversion-sdk init
Route::post('/botversion/chat', function (\Illuminate\Http\Request $request) {
    return \BotVersion::chat($request);
});
PHP;
    }
}