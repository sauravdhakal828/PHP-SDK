<?php
// botversion-sdk-php/Scanner.php

class BotVersionScanner
{
    /**
     * Scan all registered Laravel routes
     */
    public static function scanLaravelRoutes(): array
    {
        $endpoints = [];
        $seen      = [];

        try {
            $routes = app('router')->getRoutes();

            foreach ($routes as $route) {
                $methods = $route->methods();
                $path    = '/' . ltrim($route->uri(), '/');

                // Skip internal Laravel/package routes
                if (str_starts_with($path, '/_ignition') ||
                    str_starts_with($path, '/sanctum') ||
                    str_starts_with($path, '/telescope') ||
                    str_starts_with($path, '/horizon')) {
                    continue;
                }

                // Normalize Laravel path format {id} → :id
                $normalizedPath = self::normalizeLaravelPath($path);

                foreach ($methods as $method) {
                    // Skip HEAD and OPTIONS — Laravel adds these automatically
                    if (in_array($method, ['HEAD', 'OPTIONS'])) continue;

                    $key = $method . ':' . $normalizedPath;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;

                    $handlerName = self::getHandlerName($route);
                    $params      = self::extractPathParams($normalizedPath);

                    $endpoints[] = [
                        'method'      => $method,
                        'path'        => $normalizedPath,
                        'description' => self::generateDescription($method, $normalizedPath, $handlerName),
                        'requestBody' => ($method !== 'GET' && !empty($params))
                            ? self::buildParamSchema($params)
                            : null,
                        'detectedBy'  => 'static-scan',
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("[BotVersion SDK] ⚠ Laravel scan error: " . $e->getMessage());
        }

        return $endpoints;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Convert Laravel path format to standard :param format
     * /users/{id}/posts/{postId} → /users/:id/posts/:postId
     */
    private static function normalizeLaravelPath(string $path): string
    {
        return preg_replace('/\{([^}?]+)\??}/', ':$1', $path);
    }

    /**
     * Try to get a meaningful handler name from the route
     */
    private static function getHandlerName($route): ?string
    {
        $action = $route->getActionName();

        // Skip closures
        if ($action === 'Closure') return null;

        // Controller@method → extract method name
        if (str_contains($action, '@')) {
            return explode('@', $action)[1];
        }

        // Invokable controller — use class name
        if (class_exists($action)) {
            $parts = explode('\\', $action);
            return end($parts);
        }

        return null;
    }

    /**
     * Extract :param names from a path
     */
    private static function extractPathParams(string $path): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Build a simple schema from param names
     */
    private static function buildParamSchema(array $params): array
    {
        $properties = [];
        foreach ($params as $param) {
            $properties[$param] = ['type' => 'string'];
        }
        return [
            'type'       => 'object',
            'properties' => $properties,
        ];
    }

    /**
     * Generate a human-readable description
     */
    private static function generateDescription(string $method, string $path, ?string $handlerName): string
    {
        if ($handlerName) {
            $name = preg_replace('/([A-Z])/', ' $1', $handlerName);
            $name = str_replace('_', ' ', $name);
            return ucwords(strtolower(trim($name)));
        }

        $segments = array_filter(explode('/', $path), fn($s) => $s && !str_starts_with($s, ':'));
        $resource = end($segments) ?: 'resource';
        $resource = ucwords(str_replace(['-', '_'], ' ', $resource));

        $verbs = [
            'GET'    => 'Get',
            'POST'   => 'Create',
            'PUT'    => 'Update',
            'PATCH'  => 'Partially Update',
            'DELETE' => 'Delete',
        ];

        $verb = $verbs[$method] ?? $method;
        return "{$verb} {$resource}";
    }
}
