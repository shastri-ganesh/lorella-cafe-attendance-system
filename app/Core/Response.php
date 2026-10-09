<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Response
 * Standardized HTTP response builder conforming to API contracts.
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $content = '';

    public function __construct(mixed $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public static function create(mixed $content = '', int $statusCode = 200, array $headers = []): self
    {
        return new self($content, $statusCode, $headers);
    }

    /**
     * Standardized JSON success response.
     */
    public static function jsonSuccess(mixed $data = [], string $message = 'Success', int $statusCode = 200): self
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        return (new self())
            ->setStatusCode($statusCode)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Standardized JSON error response.
     */
    public static function jsonError(string $message = 'An error occurred', array $errors = [], int $statusCode = 400): self
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ];

        return (new self())
            ->setStatusCode($statusCode)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Set HTTP status code.
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Set header.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set raw content.
     */
    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Send headers and output body to client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo is_string($this->content) ? $this->content : json_encode($this->content);
        exit;
    }
}
