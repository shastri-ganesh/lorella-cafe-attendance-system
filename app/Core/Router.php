<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Class Router
 * Lightweight, extensible REST-ready router supporting middleware pipelines and route groups.
 */
class Router
{
    private array $routes = [];
    private array $groupStack = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Group routes with common prefix and middleware.
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    /**
     * Internal route addition.
     */
    private function addRoute(string $method, string $path, array|callable $handler, array $middleware = []): void
    {
        $prefix = '';
        $groupMiddleware = [];

        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
            if (isset($group['middleware'])) {
                $groupMiddleware = array_merge($groupMiddleware, (array) $group['middleware']);
            }
        }

        $fullPath = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        if ($fullPath === '') {
            $fullPath = '/';
        }

        $mergedMiddleware = array_merge($groupMiddleware, $middleware);

        // Convert {param} placeholders into regex captures
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $mergedMiddleware,
        ];
    }

    /**
     * Dispatch incoming request through middleware and invoke controller.
     */
    public function dispatch(Request $request): void
    {
        $requestMethod = $request->getMethod();
        $requestPath = $request->getPath();

        $matchedRoute = null;
        $params = [];
        $methodAllowed = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $requestPath, $matches)) {
                if ($route['method'] === $requestMethod) {
                    $matchedRoute = $route;
                    // Extract named parameters
                    foreach ($matches as $key => $value) {
                        if (is_string($key)) {
                            $params[$key] = $value;
                        }
                    }
                    break;
                } else {
                    $methodAllowed = true;
                }
            }
        }

        // Handle 404 / 405
        if (!$matchedRoute) {
            if ($methodAllowed) {
                if ($request->expectsJson()) {
                    Response::jsonError('Method Not Allowed', [], 405)->send();
                } else {
                    Response::create('405 Method Not Allowed', 405)->send();
                }
            } else {
                if ($request->expectsJson()) {
                    Response::jsonError("Endpoint not found: {$requestPath}", [], 404)->send();
                } else {
                    Response::create('404 Not Found', 404)->send();
                }
            }
            return;
        }

        try {
            // Execute route middleware pipeline
            foreach ($matchedRoute['middleware'] as $middlewareClass) {
                if (is_string($middlewareClass) && class_exists($middlewareClass)) {
                    $middleware = new $middlewareClass();
                    if (method_exists($middleware, 'handle')) {
                        $result = $middleware->handle($request);
                        if ($result instanceof Response) {
                            $result->send();
                            return;
                        }
                    }
                }
            }

            // Execute handler
            $handler = $matchedRoute['handler'];
            if (is_callable($handler)) {
                $response = call_user_func($handler, $request, $params);
            } elseif (is_array($handler) && count($handler) === 2) {
                [$controllerClass, $method] = $handler;
                $controller = new $controllerClass();
                $response = call_user_func([$controller, $method], $request, $params);
            } else {
                throw new \RuntimeException('Invalid route handler specified.');
            }

            if ($response instanceof Response) {
                $response->send();
            }
        } catch (Throwable $e) {
            $debug = (bool) Env::get('APP_DEBUG', false);
            $message = $debug ? $e->getMessage() : 'An internal server error occurred.';
            $errors = $debug ? ['file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()] : [];

            if ($request->expectsJson()) {
                Response::jsonError($message, $errors, 500)->send();
            } else {
                Response::create("500 Internal Server Error: {$message}", 500)->send();
            }
        }
    }
}
