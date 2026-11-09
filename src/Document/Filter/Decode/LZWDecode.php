<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Filter\Decode;

use PrinsFrank\PdfParser\Exception\InvalidArgumentException;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

/** @internal */
class LZWDecode {
    public static function decodeBinary(string $value, int $earlyChange = 1): string {
        if ($earlyChange !== 0 && $earlyChange !== 1) {
            throw new InvalidArgumentException('LZW EarlyChange must be zero or one');
        }
        $literalTable = array_map(chr(...), range(0, 255));
        $dictionary = $literalTable;
        $width = 9;
        $nextCode = 258;
        $buffer = $bits = $offset = 0;
        $size = strlen($value);
        $previous = null;
        $decoded = '';
        while (true) {
            while ($bits < $width) {
                if ($offset >= $size) {
                    throw new ParseFailureException('LZW stream is missing its end-of-data code');
                }
                $buffer = ($buffer << 8) | ord($value[$offset++]);
                $bits += 8;
            }
            $bits -= $width;
            $code = ($buffer >> $bits) & ((1 << $width) - 1);
            $buffer &= (1 << $bits) - 1;
            if ($code === 256) {
                $dictionary = $literalTable;
                $width = 9;
                $nextCode = 258;
                $previous = null;
                continue;
            }
            if ($code === 257) {
                return $decoded;
            }
            $entry = $dictionary[$code] ?? ($code === $nextCode && $previous !== null ? $previous . $previous[0] : null);
            if ($entry === null) {
                throw new ParseFailureException(sprintf('Invalid LZW dictionary code %d', $code));
            }
            if (strlen($entry) > 64 * 1024 * 1024 - strlen($decoded)) {
                throw new ParseFailureException('Decoded LZW stream exceeds 64 MiB');
            }
            $decoded .= $entry;
            if ($previous !== null && $nextCode < 4096) {
                $dictionary[$nextCode++] = $previous . $entry[0];
                $width = self::nextWidth($width, $nextCode, $earlyChange);
            }
            $previous = $entry;
        }
    }

    private static function nextWidth(int $width, int $nextCode, int $earlyChange): int {
        return $width < 12 && $nextCode + $earlyChange === (1 << $width) ? $width + 1 : $width;
    }
}
