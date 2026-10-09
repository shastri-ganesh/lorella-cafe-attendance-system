<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Request
 * Represents incoming HTTP request, sanitizing and normalizing input data.
 */
class Request
{
    private array $get;
    private array $post;
    private ?array $json = null;
    private array $files;
    private array $server;
    private string $method;
    private string $path;
    private array $params = [];

    public function __construct(
        ?array $get = null,
        ?array $post = null,
        ?array $files = null,
        ?array $server = null
    ) {
        $this->get = $get ?? $_GET;
        $this->post = $post ?? $_POST;
        $this->files = $files ?? $_FILES;
        $this->server = $server ?? $_SERVER;

        // Determine HTTP method (supporting override)
        $rawMethod = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($rawMethod === 'POST') {
            if (!empty($this->server['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $rawMethod = strtoupper($this->server['HTTP_X_HTTP_METHOD_OVERRIDE']);
            } elseif (!empty($this->post['_method'])) {
                $rawMethod = strtoupper($this->post['_method']);
            }
        }
        $this->method = $rawMethod;

        // Determine sanitized URI path
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Strip subfolder if app is served from a subdirectory
        $scriptName = $this->server['SCRIPT_NAME'] ?? '';
        $baseDir = dirname($scriptName);
        if ($baseDir !== '/' && $baseDir !== '\\' && str_starts_with($path, $baseDir)) {
            $path = substr($path, strlen($baseDir));
        }

        $this->path = '/' . trim($path, '/');
    }

    /**
     * Create request from PHP globals.
     */
    public static function createFromGlobals(): self
    {
        return new self();
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    /**
     * Set matched route parameters.
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Retrieve single route parameter.
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Retrieve all route parameters.
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Retrieve parameter from $_GET.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Retrieve parameter from $_POST.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Retrieve decoded JSON body.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->json === null) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }

        if ($key === null) {
            return $this->json;
        }

        return $this->json[$key] ?? $default;
    }

    /**
     * Retrieve value from JSON body, POST, or GET (in that order).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        if (array_key_exists($key, $this->get)) {
            return $this->get[$key];
        }

        return $default;
    }

    /**
     * Retrieve all input data combined.
     */
    public function all(): array
    {
        $json = $this->json();
        return array_merge($this->get, $this->post, is_array($json) ? $json : []);
    }

    /**
     * Retrieve uploaded file data.
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Check if request has an uploaded file.
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * Retrieve request header.
     */
    public function header(string $key, ?string $default = null): ?string
    {
        $formatted = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        if (isset($this->server[$formatted])) {
            return $this->server[$formatted];
        }

        // Try direct lookup
        if (isset($this->server[$key])) {
            return $this->server[$key];
        }

        return $default;
    }

    /**
     * Retrieve Bearer token from Authorization header.
     */
    public function bearerToken(): ?string
    {
        $authHeader = $this->header('Authorization') ?? $this->server['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Retrieve client IP address securely.
     */
    public function ip(): string
    {
        if (!empty($this->server['HTTP_CLIENT_IP'])) {
            return $this->server['HTTP_CLIENT_IP'];
        }
        if (!empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            // Get first IP in proxy list
            $ips = explode(',', $this->server['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Retrieve User-Agent.
     */
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    /**
     * Check if request is expecting JSON.
     */
    public function expectsJson(): bool
    {
        $accept = $this->header('Accept') ?? '';
        return str_contains($accept, '/json') || str_starts_with($this->path, '/api');
    }
}
