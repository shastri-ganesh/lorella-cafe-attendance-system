<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;

/**
 * Class RateLimitMiddleware
 * Sliding window rate limiting backed by MySQL rate_limits table.
 */
class RateLimitMiddleware
{
    private int $maxAttempts;
    private int $decaySeconds;

    public function __construct(int $maxAttempts = 60, int $decaySeconds = 60)
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
    }

    /**
     * Factory for custom limits.
     */
    public static function per(int $maxAttempts, int $decaySeconds): callable
    {
        return function (Request $request) use ($maxAttempts, $decaySeconds): ?Response {
            $limiter = new self($maxAttempts, $decaySeconds);
            return $limiter->handle($request);
        };
    }

    public function handle(Request $request): ?Response
    {
        $ip = $request->ip();
        $path = $request->getPath();
        $rateKey = hash('sha256', "rate:{$ip}:{$path}");

        try {
            $pdo = Database::getConnection();
            $now = time();

            // Clean expired records
            $cleanStmt = $pdo->prepare("DELETE FROM rate_limits WHERE window_expires_at < :now");
            $cleanStmt->execute([':now' => date('Y-m-d H:i:s', $now)]);

            // Check or increment
            $stmt = $pdo->prepare("SELECT hits, window_expires_at FROM rate_limits WHERE rate_key = :key");
            $stmt->execute([':key' => $rateKey]);
            $record = $stmt->fetch();

            if ($record) {
                if ($record['hits'] >= $this->maxAttempts) {
                    $retryAfter = max(1, strtotime($record['window_expires_at']) - $now);
                    $response = Response::jsonError("Too many requests. Please retry in {$retryAfter} seconds.", [], 429);
                    $response->setHeader('Retry-After', (string) $retryAfter);
                    return $response;
                }

                $updateStmt = $pdo->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE rate_key = :key");
                $updateStmt->execute([':key' => $rateKey]);
            } else {
                $expiresAt = date('Y-m-d H:i:s', $now + $this->decaySeconds);
                $insertStmt = $pdo->prepare("INSERT INTO rate_limits (rate_key, hits, window_expires_at) VALUES (:key, 1, :expires)");
                $insertStmt->execute([':key' => $rateKey, ':expires' => $expiresAt]);
            }
        } catch (\Throwable $e) {
            // Fail open on database error to avoid blocking valid traffic if table is not yet migrated
            return null;
        }

        return null;
    }
}
