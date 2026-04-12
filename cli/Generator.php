<?php
// botversion-sdk-php/cli/Generator.php

class BotVersionGenerator
{
    // ─── SERVICE PROVIDER INIT CODE ──────────────────────────────────────────

    public static function generateLaravelServiceProviderCode(array $auth = []): string
    {
        $userContextCode = self::generateUserContext($auth);

        return <<<PHP
// BotVersion AI Agent — auto-added by botversion-sdk init
\\BotVersion::init(env('BOTVERSION_API_KEY'), [
    // 'debug' => true,
{$userContextCode}
]);
PHP;
    }

    private static function generateUserContext(array $auth): string
    {
        $authName = $auth['name'] ?? null;

        switch ($authName) {
            case 'jwt-auth':
                return <<<'PHP'
    // JWT Auth detected — user is resolved from the API guard
    'get_user_context' => fn($request) => [
        'userId' => auth('api')->user()?->id,
        'email'  => auth('api')->user()?->email,
    ],
PHP;

            case 'sanctum':
            case 'passport':
            case 'laravel-auth':
            case 'breeze':
            case 'jetstream':
            case 'fortify':
                return <<<'PHP'
    // Laravel auth detected — user resolved from request
    'get_user_context' => fn($request) => [
        'userId' => $request->user()?->id,
        'email'  => $request->user()?->email,
    ],
PHP;

            case 'spatie-permission':
                return <<<'PHP'
    // Spatie permissions detected — includes user role
    'get_user_context' => fn($request) => [
        'userId' => $request->user()?->id,
        'email'  => $request->user()?->email,
        'role'   => $request->user()?->getRoleNames()->first(),
    ],
PHP;

            default:
                return <<<'PHP'
    // No auth detected — add user context manually if needed
    // 'get_user_context' => fn($request) => [
    //     'userId' => $request->user()?->id,
    //     'email'  => $request->user()?->email,
    // ],
PHP;
        }
    }

    // ─── CHAT ROUTE CODE ─────────────────────────────────────────────────────

    public static function generateLaravelChatRoute(array $auth = []): string
    {
        $middleware        = self::resolveMiddleware($auth);
        $middlewareLine    = $middleware ? "->middleware('{$middleware}')" : '';
        $middlewareComment = $middleware
            ? "// Protected by {$middleware} middleware"
            : "// No auth detected — add ->middleware('auth:sanctum') if needed";

        return <<<PHP

// BotVersion AI Agent chat endpoint — auto-added by botversion-sdk init
{$middlewareComment}
Route::post('/botversion/chat', function (\\Illuminate\\Http\\Request \$request) {
    return \\BotVersion::chat(\$request);
}){$middlewareLine};
PHP;
    }

    private static function resolveMiddleware(array $auth): ?string
    {
        switch ($auth['name'] ?? null) {
            case 'sanctum':                          return 'auth:sanctum';
            case 'passport':                         return 'auth:api';
            case 'jwt-auth':                         return 'auth:api';
            case 'laravel-auth':
            case 'breeze':
            case 'jetstream':
            case 'fortify':                          return 'auth';
            default:                                 return null;
        }
    }

    // ─── SCRIPT TAG GENERATION ────────────────────────────────────────────────

    public static function generateScriptTag(array $projectInfo): string
    {
        $cdnUrl    = $projectInfo['cdnUrl']    ?? '';
        $apiUrl    = $projectInfo['apiUrl']    ?? '';
        $projectId = $projectInfo['projectId'] ?? '';
        $publicKey = $projectInfo['publicKey'] ?? '';

        // Note: data-proxy-url assumes default Laravel API prefix (/api).
        // If you changed the prefix in RouteServiceProvider, update this value.
        return <<<HTML
<script
  id="botversion-loader"
  src="{$cdnUrl}"
  data-api-url="{$apiUrl}"
  data-project-id="{$projectId}"
  data-public-key="{$publicKey}"
  data-proxy-url="/api/botversion/chat"
></script>
HTML;
    }
}