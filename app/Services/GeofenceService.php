<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Class GeofenceService
 * Server-authoritative geospatial distance calculation and geofence evaluation.
 * Uses spherical Haversine formula (Mean Earth radius = 6,371,000 meters).
 */
class GeofenceService
{
    public const EARTH_RADIUS_METERS = 6371000.0;
    public const MAX_ALLOWED_GPS_ACCURACY_METERS = 150.0; // GPS reading too inaccurate if > 150m

    /**
     * Calculate spherical distance between two points in meters.
     */
    public static function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $lat1Rad = deg2rad($lat1);
        $lng1Rad = deg2rad($lng1);
        $lat2Rad = deg2rad($lat2);
        $lng2Rad = deg2rad($lng2);

        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLng = $lng2Rad - $lng1Rad;

        $a = (sin($deltaLat / 2) ** 2) +
             cos($lat1Rad) * cos($lat2Rad) * (sin($deltaLng / 2) ** 2);

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }

    /**
     * Evaluate if an employee's reported coordinates fall within café boundaries.
     *
     * @param float $userLat Reported latitude
     * @param float $userLng Reported longitude
     * @param float $userAccuracy Reported GPS accuracy in meters
     * @param float $cafeLat Configured café latitude
     * @param float $cafeLng Configured café longitude
     * @param float $allowedRadiusMeters Configured café boundary radius
     * @return array Evaluation report
     */
    public static function evaluateLocation(
        float $userLat,
        float $userLng,
        float $userAccuracy,
        float $cafeLat,
        float $cafeLng,
        float $allowedRadiusMeters
    ): array {
        // Step 1: Validate coordinate boundaries
        if ($userLat < -90.0 || $userLat > 90.0 || $userLng < -180.0 || $userLng > 180.0) {
            return [
                'is_valid' => false,
                'distance_meters' => 0.0,
                'accuracy_acceptable' => false,
                'message' => 'Invalid geographic coordinates provided.',
            ];
        }

        // Step 2: Validate GPS accuracy
        $accuracyAcceptable = ($userAccuracy > 0.0 && $userAccuracy <= self::MAX_ALLOWED_GPS_ACCURACY_METERS);
        if (!$accuracyAcceptable) {
            return [
                'is_valid' => false,
                'distance_meters' => 0.0,
                'accuracy_acceptable' => false,
                'message' => "GPS accuracy is too low ({$userAccuracy}m). Please enable high-accuracy location.",
            ];
        }

        // Step 3: Calculate Haversine distance
        $distanceMeters = self::calculateDistance($userLat, $userLng, $cafeLat, $cafeLng);

        // Allow a small dynamic buffer based on GPS accuracy (up to 20m) to accommodate physical walls
        $effectiveBuffer = min(20.0, $userAccuracy * 0.25);
        $isWithin = $distanceMeters <= ($allowedRadiusMeters + $effectiveBuffer);

        return [
            'is_valid' => $isWithin,
            'distance_meters' => $distanceMeters,
            'accuracy_acceptable' => true,
            'allowed_radius_meters' => $allowedRadiusMeters,
            'message' => $isWithin
                ? 'Location verified within café premises.'
                : "Location outside café perimeter ({$distanceMeters}m away; limit {$allowedRadiusMeters}m).",
        ];
    }
}
