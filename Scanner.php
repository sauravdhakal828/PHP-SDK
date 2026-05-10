<?php
// botversion-sdk-php/Scanner.php

class BotVersionScanner
{
    /**
     * Scan all registered Laravel routes and extract request body schemas.
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
                if (
                    str_starts_with($path, '/_ignition') ||
                    str_starts_with($path, '/sanctum')   ||
                    str_starts_with($path, '/telescope')  ||
                    str_starts_with($path, '/horizon')
                ) {
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
                    $requestBody = null;

                    // Only extract request body for POST, PUT, PATCH
                    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                        $requestBody = self::extractRequestBody($route);
                    }

                    $endpoints[] = [
                        'method'      => $method,
                        'path'        => $normalizedPath,
                        'description' => self::generateDescription($method, $normalizedPath, $handlerName),
                        'requestBody' => $requestBody,
                        'detectedBy'  => 'static-scan',
                    ];
                }
            }
        } catch (\Exception $e) {
        }

        return $endpoints;
    }

    // ── Request Body Extraction ───────────────────────────────────────────────

    /**
     * Main entry point for extracting request body schema from a route.
     * Tries multiple strategies in order of accuracy.
     */
    private static function extractRequestBody($route): ?array
    {
        // Strategy 1: FormRequest class — most accurate
        $formRequestSchema = self::extractFromFormRequest($route);
        if ($formRequestSchema) return $formRequestSchema;

        // Strategy 2: inline $request->validate() inside controller method
        $inlineValidationSchema = self::extractFromInlineValidation($route);
        if ($inlineValidationSchema) return $inlineValidationSchema;

        // Strategy 3: $request->input(), $request->get(), $request->only() calls
        $inputSchema = self::extractFromRequestInputCalls($route);
        if ($inputSchema) return $inputSchema;

        return null;
    }

    /**
     * Strategy 1 — Extract fields from a FormRequest class.
     *
     * Laravel FormRequests define validation rules in a rules() method.
     * Example:
     *   public function rules() {
     *       return ['email' => 'required|email', 'password' => 'required'];
     *   }
     *
     * Handles:
     *   - Type-hinted FormRequest parameters in controller methods
     *   - FormRequest classes anywhere in the app/Http/Requests directory
     */
    private static function extractFromFormRequest($route): ?array
    {
        try {
            $action = $route->getAction();

            // Get the controller class and method
            if (!isset($action['uses']) || $action['uses'] === 'Closure') {
                return null;
            }

            $uses = $action['uses'];

            if ($uses instanceof \Closure) {
                return null;
            }

            // Handle Controller@method format
            if (str_contains($uses, '@')) {
                [$controllerClass, $methodName] = explode('@', $uses);
            } else {
                // Invokable controller
                $controllerClass = $uses;
                $methodName      = '__invoke';
            }

            if (!class_exists($controllerClass)) return null;

            $reflection = new \ReflectionMethod($controllerClass, $methodName);
            $parameters = $reflection->getParameters();

            foreach ($parameters as $param) {
                $type = $param->getType();
                if (!$type || $type->isBuiltin()) continue;

                $typeName = $type->getName();
                if (!class_exists($typeName)) continue;

                // Check if it extends FormRequest
                if (!is_subclass_of($typeName, \Illuminate\Foundation\Http\FormRequest::class)) continue;

                // Instantiate and call rules()
                // Use app() to resolve dependencies via Laravel's service container.
                // If that fails (e.g. outside request context), skip silently.
                try {
                    $formRequest = app($typeName);
                } catch (\Exception $e) {
                    continue;
                }

                if (!method_exists($formRequest, 'rules')) continue;

                try {
                    $rules = $formRequest->rules();
                } catch (\Exception $e) {
                    continue;
                }
                if (empty($rules)) return null;

                return self::rulesArrayToSchema($rules);
            }
        } catch (\Exception $e) {
            // Silent fail — try next strategy
        }

        return null;
    }

    /**
     * Strategy 2 — Extract fields from inline $request->validate() calls.
     *
     * Example:
     *   $request->validate([
     *       'email'    => 'required|email',
     *       'password' => 'required|min:8',
     *   ]);
     *
     * Reads the controller method source code and parses the validation array.
     */
    private static function extractFromInlineValidation($route): ?array
    {
        try {
            $src = self::getControllerMethodSource($route);
            if (!$src) return null;

            // Match $request->validate([...]) or $this->validate($request, [...])
            // Pattern captures the array content between the brackets
            $patterns = [
                '/\$request\s*->\s*validate\s*\(\s*\[([^\]]+)\]/s',
                '/\$this\s*->\s*validate\s*\(\s*\$\w+\s*,\s*\[([^\]]+)\]/s',
                '/Validator\s*::\s*make\s*\(\s*\$\w+\s*,\s*\[([^\]]+)\]/s',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $src, $match)) {
                    $fields = self::parseValidationArrayString($match[1]);
                    if (!empty($fields)) {
                        return self::fieldsToSchema($fields, $src);
                    }
                }
            }
        } catch (\Exception $e) {
            // Silent fail — try next strategy
        }

        return null;
    }

    /**
     * Strategy 3 — Extract fields from $request->input(), $request->get(),
     * $request->only(), $request->filled() calls.
     *
     * Examples:
     *   $request->input('email')
     *   $request->get('name')
     *   $request->only(['email', 'password'])
     *   $request->filled('token')
     *   $name = $request->name  ← magic property access
     */
    private static function extractFromRequestInputCalls($route): ?array
    {
        try {
            $src = self::getControllerMethodSource($route);
            if (!$src) return null;

            $fields = [];

            // $request->input('field') or $request->get('field') or $request->filled('field')
            preg_match_all(
                '/\$request\s*->\s*(?:input|get|filled|has|whenHas|missing)\s*\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
                $src,
                $matches
            );
            foreach ($matches[1] as $field) {
                $fields[$field] = true;
            }

            // $request->only(['field1', 'field2']) or $request->only('field1', 'field2')
            preg_match_all(
                '/\$request\s*->\s*only\s*\(([^)]+)\)/',
                $src,
                $onlyMatches
            );
            foreach ($onlyMatches[1] as $onlyArgs) {
                preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/i', $onlyArgs, $fieldMatches);
                foreach ($fieldMatches[1] as $field) {
                    $fields[$field] = true;
                }
            }

            // $request->except(['field']) — these are fields being excluded,
            // so we skip this pattern as it tells us what's NOT in the body

            // $request->name — magic property access (common in Laravel)
            preg_match_all(
                '/\$request\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)(?!\s*\()/',
                $src,
                $magicMatches
            );
            // Filter out Laravel Request methods to avoid false positives
            $laravelMethods = [
                'validate', 'all', 'input', 'get', 'post', 'query', 'file',
                'hasFile', 'has', 'filled', 'missing', 'only', 'except',
                'merge', 'replace', 'flash', 'flush', 'old', 'method',
                'isMethod', 'header', 'ip', 'url', 'path', 'route',
                'user', 'bearerToken', 'ajax', 'wantsJson', 'json',
                'cookie', 'session', 'server', 'keys', 'collect',
            ];
            foreach ($magicMatches[1] as $field) {
                if (!in_array($field, $laravelMethods)) {
                    $fields[$field] = true;
                }
            }

            if (empty($fields)) return null;

            return self::fieldsToSchema(array_keys($fields), $src);

        } catch (\Exception $e) {
            // Silent fail
        }

        return null;
    }

    // ── Schema Helpers ────────────────────────────────────────────────────────

    /**
     * Convert a Laravel validation rules array to a JSON schema.
     *
     * Handles:
     *   'email'    => 'required|email'
     *   'age'      => 'required|integer'
     *   'is_admin' => ['required', 'boolean']
     *   'name.*'   => 'string'  ← nested arrays, simplified
     */
    private static function rulesArrayToSchema(array $rules): array
    {
        $properties = [];
        $required   = [];

        foreach ($rules as $field => $rule) {
            // Skip nested array rules like 'items.*.name' — use base field only
            $baseField = explode('.', $field)[0];
            if (isset($properties[$baseField])) continue;

            // Normalize rule to array format
            if (is_string($rule)) {
                $ruleParts = explode('|', $rule);
            } elseif (is_array($rule)) {
                $ruleParts = array_map(function($r) {
                    if (is_object($r)) {
                    // Handle Laravel rule objects like Password, Exists, Unique, etc.
                        if (method_exists($r, '__toString')) {
                            return (string) $r;
                        }
                        return class_basename($r); // fallback: just use class name e.g. "Password"
                    }
                    return strval($r);
                }, $rule);
            } else {
                $ruleParts = [];
            }

            $type = self::laravelRuleToJsonType($ruleParts);

            $properties[$baseField] = [
                'type'        => $type,
                'description' => ucwords(str_replace('_', ' ', $baseField)),
            ];

            // Mark as required if 'required' rule present and not 'nullable'
            if (
                in_array('required', $ruleParts) &&
                !in_array('nullable', $ruleParts)
            ) {
                $required[] = $baseField;
            }
        }

        if (empty($properties)) return [];

        $schema = ['type' => 'object', 'properties' => $properties];
        if (!empty($required)) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * Map Laravel validation rule parts to a JSON schema type.
     */
    private static function laravelRuleToJsonType(array $ruleParts): string
    {
        foreach ($ruleParts as $rule) {
            $rule = strtolower(trim(explode(':', $rule)[0]));
            switch ($rule) {
                case 'integer':
                case 'int':
                case 'digits':
                case 'digits_between':
                    return 'integer';
                case 'numeric':
                case 'decimal':
                    return 'number';
                case 'boolean':
                case 'bool':
                    return 'boolean';
                case 'array':
                    return 'array';
                case 'file':
                case 'image':
                case 'mimes':
                case 'mimetypes':
                    return 'file';
            }
        }
        return 'string';
    }

    /**
     * Convert a simple list of field names to a JSON schema.
     */
    private static function fieldsToSchema(array $fields, string $sourceCode = ''): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $type = $sourceCode ? self::inferFieldType($field, $sourceCode) : 'string';
            $properties[$field] = [
                'type'        => $type,
                'description' => ucwords(str_replace('_', ' ', $field)),
            ];
        }
        return ['type' => 'object', 'properties' => $properties];
    }


    private static function inferFieldType(string $fieldName, string $sourceCode): string
    {
        // Check for array usage
        $arrayPatterns = [
            '/\$' . $fieldName . '\s*\[\s*\d+\s*\]/',
            '/foreach\s*\(\s*\$' . $fieldName . '\s+as\s*/',
            '/count\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/is_array\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/array_map\s*\([^,]+,\s*\$' . $fieldName . '\s*\)/',
        ];
        foreach ($arrayPatterns as $pattern) {
            if (preg_match($pattern, $sourceCode)) return 'array';
        }

        // Check for number usage
        $numberPatterns = [
            '/\$' . $fieldName . '\s*[+\-*\/%]\s*\d/',
            '/intval\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/floatval\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/is_int\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/is_float\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/is_numeric\s*\(\s*\$' . $fieldName . '\s*\)/',
        ];
        foreach ($numberPatterns as $pattern) {
            if (preg_match($pattern, $sourceCode)) return 'number';
        }

        // Check for boolean usage
        $boolPatterns = [
            '/\$' . $fieldName . '\s*===?\s*(true|false)/',
            '/(true|false)\s*===?\s*\$' . $fieldName . '/',
            '/is_bool\s*\(\s*\$' . $fieldName . '\s*\)/',
            '/filter_var\s*\(\s*\$' . $fieldName . '\s*,\s*FILTER_VALIDATE_BOOLEAN\s*\)/',
        ];
        foreach ($boolPatterns as $pattern) {
            if (preg_match($pattern, $sourceCode)) return 'boolean';
        }

        return 'string';
    }

    /**
     * Parse a raw PHP array string from source code and extract field names.
     *
     * Input:  "'email' => 'required|email', 'password' => 'required'"
     * Output: ['email', 'password']
     */
    private static function parseValidationArrayString(string $arrayStr): array
    {
        $fields = [];
        // Match 'fieldname' => or "fieldname" =>
        preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'"\s]*=>/', $arrayStr, $matches);
        foreach ($matches[1] as $field) {
            // Skip nested like 'items.*.name' — use base field
            $baseField = explode('.', $field)[0];
            $fields[$baseField] = true;
        }
        return array_keys($fields);
    }

    // ── Source Code Helpers ───────────────────────────────────────────────────

    /**
     * Get the source code of the controller method for a given route.
     * Returns null if the route uses a Closure or source cannot be read.
     */
    private static function getControllerMethodSource($route): ?string
    {
        try {
            $action = $route->getAction();

            if (!isset($action['uses']) || $action['uses'] === 'Closure') {
                // For closures, try to get source via ReflectionFunction
                if (isset($action['uses']) && $action['uses'] instanceof \Closure) {
                    $ref = new \ReflectionFunction($action['uses']);
                    return self::extractSourceFromFile(
                        $ref->getFileName(),
                        $ref->getStartLine(),
                        $ref->getEndLine()
                    );
                }
                return null;
            }

            $uses = $action['uses'];

            if ($uses instanceof \Closure) {
            // already handled above via ReflectionFunction
                return null;
            }

            if (str_contains($uses, '@')) {
                [$controllerClass, $methodName] = explode('@', $uses);
            } else {
                $controllerClass = $uses;
                $methodName      = '__invoke';
            }

            if (!class_exists($controllerClass)) return null;

            $ref = new \ReflectionMethod($controllerClass, $methodName);
            return self::extractSourceFromFile(
                $ref->getFileName(),
                $ref->getStartLine(),
                $ref->getEndLine()
            );

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Read specific lines from a file.
     */
    private static function extractSourceFromFile(string $file, int $start, int $end): ?string
    {
        try {
            $lines = file($file);
            if (!$lines) return null;
            $slice = array_slice($lines, $start - 1, $end - $start + 1);
            return implode('', $slice);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ── Route Helpers ─────────────────────────────────────────────────────────

    /**
     * Convert Laravel path format to standard :param format.
     * /users/{id}/posts/{postId?} → /users/:id/posts/:postId
     */
    private static function normalizeLaravelPath(string $path): string
    {
        return preg_replace('/\{([^}?]+)\??}/', ':$1', $path);
    }

    /**
     * Try to get a meaningful handler name from the route.
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
     * Extract :param names from a normalized path.
     */
    private static function extractPathParams(string $path): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $path, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Generate a human-readable description for an endpoint.
     */
    private static function generateDescription(string $method, string $path, ?string $handlerName): string
    {
        if ($handlerName) {
            $name = preg_replace('/([A-Z])/', ' $1', $handlerName);
            $name = str_replace('_', ' ', $name);
            return ucwords(strtolower(trim($name)));
        }

        $segments = array_filter(
            explode('/', $path),
            fn($s) => $s && !str_starts_with($s, ':')
        );
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

    // ── Frontend Route Scanning ───────────────────────────────────────────────

    public static function scanFrontendRoutes(): array
    {
        $cwd      = base_path();
        $patterns = [];
        $seen     = [];

        $dirsToScan = [
            $cwd . '/pages',
            $cwd . '/src/pages',
            $cwd . '/app',
            $cwd . '/src/app',
            $cwd . '/src/routes',
            $cwd . '/routes',
            $cwd . '/app/routes',
        ];

        foreach ($dirsToScan as $dir) {
            if (is_dir($dir)) {
                self::walkFrontendDir($dir, [], $patterns, $seen);
            }
        }

        // Also scan config-based routes (React Router, Vue Router, Angular)
        $configPatterns = self::scanConfigBasedRoutes($cwd);
        foreach ($configPatterns as $p) {
            if (!isset($seen[$p['pattern']])) {
                $seen[$p['pattern']] = true;
                $patterns[] = $p;
            }
        }

        return $patterns;
    }

    private static function walkFrontendDir(string $dir, array $segments, array &$patterns, array &$seen): void
    {
        $entries = scandir($dir);
        if (!$entries) return;

        foreach ($entries as $file) {
            if ($file === '.' || $file === '..') continue;

            $fullPath = $dir . '/' . $file;

            if (is_dir($fullPath)) {
                // Skip api folder and underscore folders
                if ($file === 'api' || str_starts_with($file, '_')) continue;

                // Skip route groups like (marketing) — Next.js feature
                if (preg_match('/^\(.*\)$/', $file)) {
                    self::walkFrontendDir($fullPath, $segments, $patterns, $seen);
                    continue;
                }

                $segment = self::convertSegment($file);
                if ($segment === null) continue;

                self::walkFrontendDir($fullPath, array_merge($segments, [$segment]), $patterns, $seen);
                continue;
            }

            // Only process known frontend file types
            if (!preg_match('/\.(js|ts|jsx|tsx|vue|svelte)$/', $file)) continue;
            if (str_starts_with($file, '_')) continue;

            $routeName = preg_replace('/\.(js|ts|jsx|tsx|vue|svelte)$/', '', $file);

            // Skip non-page files in Next.js App Router
            if (in_array($routeName, ['layout', 'loading', 'error', 'template', 'not-found'])) continue;

            // SvelteKit: only +page files are pages
            if (str_starts_with($routeName, '+') && $routeName !== '+page') continue;

            // Remix: dot-separated filenames like $projectId.dashboard.tsx
            $isRemixRoute = str_contains($routeName, '.') && !str_starts_with($routeName, '+');
            if ($isRemixRoute) {
                $remixSegments = array_map(
                    fn($s) => self::convertSegment($s) ?? $s,
                    explode('.', $routeName)
                );
                $finalSegments = array_merge($segments, $remixSegments);
            } elseif (in_array($routeName, ['index', 'page', '+page'])) {
                $finalSegments = $segments;
            } else {
                $converted = self::convertSegment($routeName);
                $finalSegments = array_merge($segments, [$converted ?? $routeName]);
            }

            $pattern = '/' . implode('/', array_filter($finalSegments));
            if (isset($seen[$pattern])) continue;
            $seen[$pattern] = true;

            $paramMap = self::extractParamPositions($finalSegments);
            if (empty($paramMap)) continue; // skip static routes with no dynamic params

            $patterns[] = ['pattern' => $pattern, 'params' => $paramMap];
        }
    }

    private static function convertSegment(string $segment): ?string
    {
        // Skip catch-all [...slug] and optional [[...slug]]
        if (preg_match('/^\[?\[\.\.\./', $segment)) return null;

        // Next.js/Nuxt [param] → :param
        if (preg_match('/^\[([^\]]+)\]$/', $segment, $m)) return ':' . $m[1];

        // Remix $param → :param
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)$/', $segment, $m)) return ':' . $m[1];

        return $segment;
    }

    private static function extractParamPositions(array $segments): array
    {
        $paramMap = [];
        foreach ($segments as $index => $segment) {
            if ($segment && str_starts_with($segment, ':')) {
                $paramName            = substr($segment, 1);
                $paramMap[$paramName] = $index;
            }
        }
        return $paramMap;
    }

    private static function scanConfigBasedRoutes(string $cwd): array
    {
        $patterns = [];
        $seen     = [];

        $filesToCheck = [
            $cwd . '/src/App.jsx',
            $cwd . '/src/App.tsx',
            $cwd . '/src/App.js',
            $cwd . '/src/router.jsx',
            $cwd . '/src/router.tsx',
            $cwd . '/src/router.js',
            $cwd . '/src/router.ts',
            $cwd . '/src/routes.jsx',
            $cwd . '/src/routes.tsx',
            $cwd . '/src/routes.js',
            $cwd . '/src/Router.jsx',
            $cwd . '/src/Router.tsx',
            $cwd . '/src/router/index.js',
            $cwd . '/src/router/index.ts',
            $cwd . '/src/app/app-routing.module.ts',
            $cwd . '/src/app/app.routes.ts',
        ];

        foreach ($filesToCheck as $filePath) {
            if (!file_exists($filePath)) continue;

            $content = file_get_contents($filePath);
            if (!$content) continue;

            // React Router JSX: <Route path="/:projectId/dashboard" />
            preg_match_all('/<Route[^>]+path=["\']([^"\']+)["\']/', $content, $matches);
            foreach ($matches[1] as $path) {
                self::addConfigPattern($path, $seen, $patterns);
            }

            // React Router / Vue Router object: { path: '/:projectId/dashboard' }
            preg_match_all('/path\s*:\s*["\']([^"\']+)["\']/', $content, $matches);
            foreach ($matches[1] as $path) {
                self::addConfigPattern($path, $seen, $patterns);
            }
        }

        return $patterns;
    }

    private static function addConfigPattern(string $routePath, array &$seen, array &$patterns): void
    {
        if (!$routePath || $routePath === '*' || $routePath === '**') return;
        if (!str_contains($routePath, ':') && !str_contains($routePath, '$')) return;

        $normalized = str_starts_with($routePath, '/') ? $routePath : '/' . $routePath;
        if (isset($seen[$normalized])) return;
        $seen[$normalized] = true;

        $segments = array_filter(explode('/', $normalized));
        $paramMap = [];
        foreach (array_values($segments) as $index => $segment) {
            if (str_starts_with($segment, ':')) {
                $paramMap[substr($segment, 1)] = $index;
            }
        }

        if (empty($paramMap)) return;

        $patterns[] = ['pattern' => $normalized, 'params' => $paramMap];
    }
}
