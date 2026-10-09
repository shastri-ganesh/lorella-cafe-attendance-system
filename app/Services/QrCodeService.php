<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Class QrCodeService
 * Pure-PHP ISO/IEC 18004 compliant QR Code generator producing crisp vector SVG output.
 * Zero external libraries, zero remote API calls, zero secrets sent over the wire.
 */
class QrCodeService
{
    // Versions 1 to 10 specifications for ECC Level M (approx 15% error recovery):
    // [total_codewords, ec_codewords_per_block, num_blocks_g1, data_per_block_g1, num_blocks_g2, data_per_block_g2]
    private const SPECS_M = [
        1 => [26, 10, 1, 16, 0, 0],
        2 => [44, 16, 1, 28, 0, 0],
        3 => [70, 26, 1, 44, 0, 0],
        4 => [100, 18, 2, 32, 0, 0],
        5 => [134, 24, 2, 43, 0, 0],
        6 => [172, 16, 4, 27, 0, 0],
        7 => [196, 18, 2, 31, 2, 32],
        8 => [242, 22, 2, 38, 2, 39],
        9 => [292, 22, 3, 36, 2, 37],
        10 => [346, 26, 4, 43, 1, 44],
    ];

    // Standard alignment pattern center coordinates for versions 1..10
    private const ALIGNMENT_COORDS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    /**
     * Generate standalone SVG markup for arbitrary text (e.g. otpauth:// URI).
     */
    public static function generateSvg(string $data, int $size = 240, int $margin = 4): string
    {
        $matrix = self::encodeMatrix($data);
        $moduleCount = count($matrix);
        $totalSize = $moduleCount + ($margin * 2);

        $path = '';
        for ($r = 0; $r < $moduleCount; $r++) {
            for ($c = 0; $c < $moduleCount; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $path .= "M{$x},{$y}h1v1h-1z ";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges">' .
            '<rect width="100%%" height="100%%" fill="#FFFFFF"/>' .
            '<path d="%s" fill="#000000"/>' .
            '</svg>',
            $totalSize,
            $totalSize,
            $size,
            $size,
            trim($path)
        );
    }

    /**
     * Generate base64-encoded SVG data URI suitable for direct <img src="..."> tags.
     */
    public static function generateDataUri(string $data, int $size = 240, int $margin = 4): string
    {
        $svg = self::generateSvg($data, $size, $margin);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Encode binary string into 2D matrix of modules (1 for dark, 0 for light).
     */
    public static function encodeMatrix(string $data): array
    {
        $len = strlen($data);
        $version = 1;

        while ($version <= 10) {
            $spec = self::SPECS_M[$version];
            $totalDataCapacity = ($spec[2] * $spec[3]) + ($spec[4] * $spec[5]);
            $headerBits = 4 + ($version >= 10 ? 16 : 8);
            $maxDataBytes = (int) floor(($totalDataCapacity * 8 - $headerBits) / 8);
            if ($len <= $maxDataBytes) {
                break;
            }
            $version++;
        }

        if ($version > 10) {
            throw new \InvalidArgumentException("Data payload too large for QR Code Version 10 ({$len} bytes).");
        }

        $spec = self::SPECS_M[$version];
        $totalDataBytes = ($spec[2] * $spec[3]) + ($spec[4] * $spec[5]);

        // 1. Bitstream creation (Byte mode)
        $bitBuffer = '0100'; // Mode: Byte
        $charCountBits = ($version >= 10) ? 16 : 8;
        $bitBuffer .= str_pad(decbin($len), $charCountBits, '0', STR_PAD_LEFT);

        for ($i = 0; $i < $len; $i++) {
            $bitBuffer .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Terminator (up to 4 bits)
        $capacityBits = $totalDataBytes * 8;
        $remaining = $capacityBits - strlen($bitBuffer);
        $terminatorLen = min(4, $remaining);
        $bitBuffer .= str_repeat('0', $terminatorLen);

        // Pad to byte boundary
        while ((strlen($bitBuffer) % 8) !== 0) {
            $bitBuffer .= '0';
        }

        // Pad codewords alternating 0xEC and 0x11
        $padBytes = ['11101100', '00010001'];
        $padIdx = 0;
        while (strlen($bitBuffer) < $capacityBits) {
            $bitBuffer .= $padBytes[$padIdx % 2];
            $padIdx++;
        }

        // Convert bit buffer to data codewords
        $dataCodewords = [];
        $chunks = str_split($bitBuffer, 8);
        foreach ($chunks as $chunk) {
            $dataCodewords[] = bindec($chunk);
        }

        // 2. Error correction calculation (Reed-Solomon)
        $blocks = [];
        $ecBlocks = [];
        $offset = 0;

        for ($b = 0; $b < $spec[2]; $b++) {
            $blk = array_slice($dataCodewords, $offset, $spec[3]);
            $offset += $spec[3];
            $blocks[] = $blk;
            $ecBlocks[] = self::rsCalculate($blk, $spec[1]);
        }
        for ($b = 0; $b < $spec[4]; $b++) {
            $blk = array_slice($dataCodewords, $offset, $spec[5]);
            $offset += $spec[5];
            $blocks[] = $blk;
            $ecBlocks[] = self::rsCalculate($blk, $spec[1]);
        }

        // 3. Interleaving data and error correction codewords
        $finalCodewords = [];
        $maxBlockLen = max($spec[3], $spec[5]);
        for ($i = 0; $i < $maxBlockLen; $i++) {
            foreach ($blocks as $blk) {
                if (isset($blk[$i])) {
                    $finalCodewords[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $spec[1]; $i++) {
            foreach ($ecBlocks as $ec) {
                $finalCodewords[] = $ec[$i];
            }
        }

        $finalBits = '';
        foreach ($finalCodewords as $cw) {
            $finalBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        // Add remainder bits
        $remainderBits = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
        $finalBits .= str_repeat('0', $remainderBits[$version] ?? 0);

        // 4. Matrix construction
        $size = ($version * 4) + 17;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Place finder patterns
        $placeFinder = function (int $top, int $left) use (&$matrix, &$reserved, $size): void {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $row = $top + $r;
                    $col = $left + $c;
                    if ($row < 0 || $row >= $size || $col < 0 || $col >= $size) {
                        continue;
                    }
                    $reserved[$row][$col] = true;
                    if ($r === -1 || $r === 7 || $c === -1 || $c === 7) {
                        $matrix[$row][$col] = 0;
                    } elseif ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) {
                        $matrix[$row][$col] = 1;
                    } else {
                        $matrix[$row][$col] = 0;
                    }
                }
            }
        };

        $placeFinder(0, 0);
        $placeFinder(0, $size - 7);
        $placeFinder($size - 7, 0);

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = ($i % 2 === 0) ? 1 : 0;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = ($i % 2 === 0) ? 1 : 0;
                $reserved[$i][6] = true;
            }
        }

        // Dark module
        $matrix[(4 * $version) + 9][8] = 1;
        $reserved[(4 * $version) + 9][8] = true;

        // Alignment patterns
        $coords = self::ALIGNMENT_COORDS[$version];
        $count = count($coords);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                $r = $coords[$i];
                $c = $coords[$j];
                if (($r === 6 && $c === 6) ||
                    ($r === 6 && $c === $coords[$count - 1]) ||
                    ($r === $coords[$count - 1] && $c === 6)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $reserved[$r + $dr][$c + $dc] = true;
                        if (abs($dr) === 2 || abs($dc) === 2 || ($dr === 0 && $dc === 0)) {
                            $matrix[$r + $dr][$c + $dc] = 1;
                        } else {
                            $matrix[$r + $dr][$c + $dc] = 0;
                        }
                    }
                }
            }
        }

        // Reserve format info modules
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // Reserve version info modules (Version >= 7)
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$size - 11 + $j][$i] = true;
                    $reserved[$i][$size - 11 + $j] = true;
                }
            }
        }

        // 5. Place data bits in zig-zag traversal
        $bitIndex = 0;
        $direction = -1;
        $row = $size - 1;
        $col = $size - 1;

        while ($col > 0) {
            if ($col === 6) {
                $col--;
            }
            for ($step = 0; $step < $size; $step++) {
                for ($k = 0; $k < 2; $k++) {
                    $curCol = $col - $k;
                    if (!$reserved[$row][$curCol]) {
                        $bit = ($bitIndex < strlen($finalBits)) ? (int) $finalBits[$bitIndex] : 0;
                        $matrix[$row][$curCol] = $bit;
                        $bitIndex++;
                    }
                }
                $row += $direction;
            }
            $direction = -$direction;
            $row += $direction;
            $col -= 2;
        }

        // 6. Select optimal mask pattern
        $masks = [
            0 => fn($r, $c) => ($r + $c) % 2 === 0,
            1 => fn($r, $c) => $r % 2 === 0,
            2 => fn($r, $c) => $c % 3 === 0,
            3 => fn($r, $c) => ($r + $c) % 3 === 0,
            4 => fn($r, $c) => ((int) floor($r / 2) + (int) floor($c / 3)) % 2 === 0,
            5 => fn($r, $c) => (($r * $c) % 2) + (($r * $c) % 3) === 0,
            6 => fn($r, $c) => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            7 => fn($r, $c) => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
        ];

        $bestMask = 0;
        $lowestPenalty = PHP_INT_MAX;

        foreach ($masks as $maskIdx => $maskFn) {
            $testMatrix = $matrix;
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if (!$reserved[$r][$c] && $maskFn($r, $c)) {
                        $testMatrix[$r][$c] ^= 1;
                    }
                }
            }
            $penalty = self::calculatePenalty($testMatrix, $size);
            if ($penalty < $lowestPenalty) {
                $lowestPenalty = $penalty;
                $bestMask = $maskIdx;
            }
        }

        // Apply best mask
        $maskFn = $masks[$bestMask];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$reserved[$r][$c]) {
                    if ($maskFn($r, $c)) {
                        $matrix[$r][$c] ^= 1;
                    }
                }
            }
        }

        // 7. Write format information
        $formatData = (0 << 3) | $bestMask; // Level M is 00
        $d = $formatData << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ($d & (1 << ($i + 10))) {
                $d ^= (0x537 << $i);
            }
        }
        $formatBits = (($formatData << 10) | $d) ^ 0x5412;
        $formatStr = str_pad(decbin($formatBits), 15, '0', STR_PAD_LEFT);

        $order1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        for ($i = 0; $i < 15; $i++) {
            [$r, $c] = $order1[$i];
            $matrix[$r][$c] = (int) $formatStr[$i];
        }

        $order2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8], [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5], [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1]
        ];
        for ($i = 0; $i < 15; $i++) {
            [$r, $c] = $order2[$i];
            $matrix[$r][$c] = (int) $formatStr[$i];
        }

        // Write version info for version >= 7
        if ($version >= 7) {
            $vData = $version;
            $d = $vData << 12;
            for ($i = 5; $i >= 0; $i--) {
                if ($d & (1 << ($i + 12))) {
                    $d ^= (0x1F25 << $i);
                }
            }
            $vBits = ($vData << 12) | $d;
            $vStr = str_pad(decbin($vBits), 18, '0', STR_PAD_LEFT);
            for ($i = 0; $i < 18; $i++) {
                $bit = (int) $vStr[17 - $i];
                $r = (int) floor($i / 3);
                $c = $i % 3;
                $matrix[$size - 11 + $c][$r] = $bit;
                $matrix[$r][$size - 11 + $c] = $bit;
            }
        }

        return $matrix;
    }

    private static function initGf(): void
    {
        if (self::$gfExp !== null) {
            return;
        }
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }

    private static function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) {
            return 0;
        }
        return self::$gfExp[self::$gfLog[$x] + self::$gfLog[$y]];
    }

    private static function rsGenPoly(int $degree): array
    {
        self::initGf();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $factor = [1, self::$gfExp[$i]];
            $newPoly = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $newPoly[$j] ^= self::gfMul($poly[$j], $factor[0]);
                $newPoly[$j + 1] ^= self::gfMul($poly[$j], $factor[1]);
            }
            $poly = $newPoly;
        }
        return $poly;
    }

    private static function rsCalculate(array $data, int $degree): array
    {
        self::initGf();
        $genPoly = self::rsGenPoly($degree);
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            if ($factor !== 0) {
                for ($i = 0; $i < $degree; $i++) {
                    $remainder[$i] ^= self::gfMul($genPoly[$i + 1], $factor);
                }
            }
        }

        return $remainder;
    }

    private static function calculatePenalty(array $matrix, int $size): int
    {
        $penalty = 0;

        for ($r = 0; $r < $size; $r++) {
            $count = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($matrix[$r][$c] === $matrix[$r][$c - 1]) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += 3 + ($count - 5);
                    }
                    $count = 1;
                }
            }
            if ($count >= 5) {
                $penalty += 3 + ($count - 5);
            }
        }

        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $val = $matrix[$r][$c];
                if ($matrix[$r + 1][$c] === $val && $matrix[$r][$c + 1] === $val && $matrix[$r + 1][$c + 1] === $val) {
                    $penalty += 3;
                }
            }
        }

        return $penalty;
    }
}
