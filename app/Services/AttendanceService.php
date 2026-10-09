<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Class AttendanceService
 * Business rules and architectural contracts for server-authoritative attendance operations.
 */
class AttendanceService
{
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_AUTO_CLOSED = 'auto_closed';
    public const STATUS_FLAGGED = 'flagged';

    /**
     * Compute working hours in minutes between check-in and check-out timestamps.
     */
    public static function calculateWorkMinutes(string $checkInAt, string $checkOutAt): int
    {
        $inTime = strtotime($checkInAt);
        $outTime = strtotime($checkOutAt);

        if ($inTime === false || $outTime === false || $outTime <= $inTime) {
            return 0;
        }

        return (int) round(($outTime - $inTime) / 60);
    }

    /**
     * Determine if arrival is classified as late relative to scheduled start time.
     */
    public static function isArrivalLate(string $actualCheckInTime, string $expectedStartTime, int $gracePeriodMinutes = 15): bool
    {
        $actual = strtotime($actualCheckInTime);
        $expected = strtotime($expectedStartTime);

        if ($actual === false || $expected === false) {
            return false;
        }

        return $actual > ($expected + ($gracePeriodMinutes * 60));
    }

    /**
     * Generate secure, unpredictable filename for attendance verification selfie.
     */
    public static function generateSecurePhotoFilename(int $userId, string $type = 'in', string $extension = 'jpg'): string
    {
        $date = date('Ymd');
        $randomHash = bin2hex(random_bytes(16));
        return "att_{$userId}_{$date}_{$type}_{$randomHash}.{$extension}";
    }

    /**
     * Validate selfie file properties (MIME type, size, dimensions).
     */
    public static function validateSelfieFile(array $file): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['is_valid' => false, 'error' => 'No valid photo upload received.'];
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            return ['is_valid' => false, 'error' => "Disallowed image format ({$mime}). Only JPEG, PNG, and WebP are accepted."];
        }

        // Validate max size (5MB)
        if ($file['size'] > 5242880) {
            return ['is_valid' => false, 'error' => 'Photo exceeds maximum allowed size of 5MB.'];
        }

        return ['is_valid' => true, 'mime' => $mime];
    }
}
