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


    public static function scanLumenRoutes(): array
    {
        $endpoints = [];
        $seen      = [];

        try {
            $app    = app();
            $router = $app->router;

            foreach ($router->getRoutes() as $route) {
                $method = strtoupper($route['method']);
                $path   = '/' . ltrim($route['uri'], '/');

                if (in_array($method, ['HEAD', 'OPTIONS'])) continue;

                $normalizedPath = preg_replace('/\{([^}?]+)\??}/', ':$1', $path);
                $key            = $method . ':' . $normalizedPath;

                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $endpoints[] = [
                    'method'      => $method,
                    'path'        => $normalizedPath,
                    'description' => self::generateDescription($method, $normalizedPath, null),
                    'requestBody' => null,
                    'detectedBy'  => 'static-scan',
                ];
            }
        } catch (\Exception $e) {
            // Silent
        }

        return $endpoints;
    }

    public static function scanSymfonyRoutes($kernel = null): array
    {
        $endpoints = [];
        $seen      = [];

        try {
            $router = null;

            // Try to get router from the kernel container
            if ($kernel && method_exists($kernel, 'getContainer')) {
                $container = $kernel->getContainer();
                if ($container->has('router')) {
                    $router = $container->get('router');
                }
            }

            // Fallback — try global container if available
            if (!$router && class_exists('\Symfony\Component\DependencyInjection\ContainerInterface')) {
                return $endpoints; // Can't scan without a router reference
            }

            if (!$router) return $endpoints;

            $routeCollection = $router->getRouteCollection();

            foreach ($routeCollection->all() as $name => $route) {
                // Skip internal Symfony routes
                if (str_starts_with($name, '_')) continue;

                $path    = $route->getPath();
                $methods = $route->getMethods();

                // If no methods defined, Symfony accepts all — default to GET
                if (empty($methods)) $methods = ['GET'];

                // Normalize Symfony path format {id} → :id
                $normalizedPath = preg_replace('/\{([^}]+)}/', ':$1', $path);

                foreach ($methods as $method) {
                    $method = strtoupper($method);
                    if (in_array($method, ['HEAD', 'OPTIONS'])) continue;

                    $key = $method . ':' . $normalizedPath;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;

                    $endpoints[] = [
                        'method'      => $method,
                        'path'        => $normalizedPath,
                        'description' => self::generateDescription($method, $normalizedPath, $name),
                        'requestBody' => null,
                        'detectedBy'  => 'static-scan',
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silent
        }

        return $endpoints;
    }

    public static function scanSlimRoutes($app = null): array
    {
        $endpoints = [];
        $seen      = [];

        try {
            if (!$app) return $endpoints;

            $routeCollector = $app->getRouteCollector();
            $routes         = $routeCollector->getRoutes();

            foreach ($routes as $route) {
                $methods = $route->getMethods();
                $pattern = $route->getPattern();

                // Normalize Slim path {id} → :id
                $normalizedPath = preg_replace('/\{([^}:]+):?[^}]*}/', ':$1', $pattern);

                foreach ($methods as $method) {
                    $method = strtoupper($method);
                    if (in_array($method, ['HEAD', 'OPTIONS'])) continue;

                    $key = $method . ':' . $normalizedPath;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;

                    $endpoints[] = [
                        'method'      => $method,
                        'path'        => $normalizedPath,
                        'description' => self::generateDescription($method, $normalizedPath, null),
                        'requestBody' => null,
                        'detectedBy'  => 'static-scan',
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silent
        }

        return $endpoints;
    }

    public static function scanCodeIgniterRoutes(): array
    {
        $endpoints = [];
        $seen      = [];

        try {
            if (!class_exists('\CodeIgniter\Config\Services')) return $endpoints;

            $routes     = \CodeIgniter\Config\Services::routes();
            $routesList = $routes->getRoutes('*');

            foreach ($routesList as $path => $handler) {
                // CI4 getRoutes() returns ['GET /users' => 'Controller::method']
                // The path key may include the method prefix or not
                $method = 'GET'; // default

                if (preg_match('/^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(.+)$/i', $path, $m)) {
                    $method = strtoupper($m[1]);
                    $path   = $m[2];
                }

                if (in_array($method, ['HEAD', 'OPTIONS'])) continue;

                // Normalize CI4 path (:num), (:segment), (:any) → :id / :param
                $normalizedPath = preg_replace('/\(:num\)/',    ':id',    $path);
                $normalizedPath = preg_replace('/\(:segment\)/', ':param', $normalizedPath);
                $normalizedPath = preg_replace('/\(:any\)/',    ':param', $normalizedPath);
                $normalizedPath = '/' . ltrim($normalizedPath, '/');

                $key = $method . ':' . $normalizedPath;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $handlerName = is_string($handler) ? $handler : null;

                $endpoints[] = [
                    'method'      => $method,
                    'path'        => $normalizedPath,
                    'description' => self::generateDescription($method, $normalizedPath, $handlerName),
                    'requestBody' => null,
                    'detectedBy'  => 'static-scan',
                ];
            }
        } catch (\Exception $e) {
            // Silent
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
}
