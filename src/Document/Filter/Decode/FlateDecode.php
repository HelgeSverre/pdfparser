<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Filter\Decode;

use PrinsFrank\PdfParser\Exception\InvalidArgumentException;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\Exception\RuntimeException;

/** @internal */
class FlateDecode {
    private const BYTES_IN_MEGABYTE = 1024 ** 2;

    /**
     * @throws PdfParserException
     * @return string in binary format
     */
    public static function decodeBinary(string $value, LZWFlatePredictorValue $predictor, int $columns = 1, int $colors = 1, int $bitsPerComponent = 8): string {
        if ($columns < 1) {
            throw new InvalidArgumentException(sprintf('Nr of columns should be equal to or bigger than 1, %d given', $columns));
        }

        $decodedValue = self::decode($value, 64 * self::BYTES_IN_MEGABYTE);
        return self::applyPredictor($decodedValue, $predictor, $columns, $colors, $bitsPerComponent);
    }

    public static function applyPredictor(string $decodedValue, LZWFlatePredictorValue $predictor, int $columns = 1, int $colors = 1, int $bitsPerComponent = 8): string {
        if ($columns < 1 || $colors < 1 || !in_array($bitsPerComponent, [1, 2, 4, 8, 16], true)) {
            throw new InvalidArgumentException('Invalid predictor dimensions or component depth');
        }
        if ($predictor === LZWFlatePredictorValue::None) {
            return $decodedValue;
        }

        $limit = 64 * self::BYTES_IN_MEGABYTE;
        if (strlen($decodedValue) > $limit || $colors > $limit || $columns > intdiv($limit * 8, $colors * $bitsPerComponent)) {
            throw new ParseFailureException('Predictor data exceeds the decoded stream limit');
        }
        $rowBytes = intdiv($columns * $colors * $bitsPerComponent + 7, 8);
        $bytesPerPixel = intdiv($colors * $bitsPerComponent + 7, 8);
        $isTIFF = $predictor === LZWFlatePredictorValue::TIFFPredictor2;
        $encodedRowBytes = $rowBytes + ($isTIFF ? 0 : 1);
        if (strlen($decodedValue) % $encodedRowBytes !== 0) {
            throw new ParseFailureException('Incomplete predictor row');
        }

        $result = '';
        $previous = str_repeat("\0", $rowBytes);
        for ($offset = 0, $length = strlen($decodedValue); $offset < $length; $offset += $encodedRowBytes) {
            if ($isTIFF) {
                $result .= self::decodeTIFFRow(substr($decodedValue, $offset, $rowBytes), $columns, $colors, $bitsPerComponent);
                continue;
            }

            $algorithm = PNGPredictorAlgorithm::tryFrom(ord($decodedValue[$offset]))
                ?? throw new ParseFailureException('Unrecognized PNG predictor algorithm');
            $row = substr($decodedValue, $offset + 1, $rowBytes);
            if ($algorithm !== PNGPredictorAlgorithm::None) {
                for ($i = 0; $i < $rowBytes; $i++) {
                    $left = $i >= $bytesPerPixel ? ord($row[$i - $bytesPerPixel]) : 0;
                    $above = ord($previous[$i]);
                    $upperLeft = $i >= $bytesPerPixel ? ord($previous[$i - $bytesPerPixel]) : 0;
                    $prediction = match ($algorithm) {
                        PNGPredictorAlgorithm::Sub => $left,
                        PNGPredictorAlgorithm::Up => $above,
                        PNGPredictorAlgorithm::Average => intdiv($left + $above, 2),
                        PNGPredictorAlgorithm::Paeth => self::paeth($left, $above, $upperLeft),
                    };
                    $row[$i] = chr((ord($row[$i]) + $prediction) & 255);
                }
            }
            $result .= $row;
            $previous = $row;
        }
        return $result;
    }

    private static function paeth(int $left, int $above, int $upperLeft): int {
        $p = $left + $above - $upperLeft;
        $pa = abs($p - $left);
        $pb = abs($p - $above);
        $pc = abs($p - $upperLeft);
        return $pa <= $pb && $pa <= $pc ? $left : ($pb <= $pc ? $above : $upperLeft);
    }

    private static function decodeTIFFRow(string $row, int $columns, int $colors, int $bits): string {
        $mask = (1 << $bits) - 1;
        for ($sample = $colors, $count = $columns * $colors; $sample < $count; $sample++) {
            $bitOffset = $sample * $bits;
            $leftBitOffset = ($sample - $colors) * $bits;
            $byte = intdiv($bitOffset, 8);
            $leftByte = intdiv($leftBitOffset, 8);
            if ($bits === 16) {
                $value = ((ord($row[$byte]) << 8 | ord($row[$byte + 1])) + (ord($row[$leftByte]) << 8 | ord($row[$leftByte + 1]))) & $mask;
                $row[$byte] = chr($value >> 8);
                $row[$byte + 1] = chr($value & 255);
            } else {
                $shift = 8 - $bits - ($bitOffset % 8);
                $leftShift = 8 - $bits - ($leftBitOffset % 8);
                $value = (((ord($row[$byte]) >> $shift) & $mask) + ((ord($row[$leftByte]) >> $leftShift) & $mask)) & $mask;
                $row[$byte] = chr((ord($row[$byte]) & ~($mask << $shift)) | ($value << $shift));
            }
        }
        return $row;
    }

    /** @param int<0, max> $maxBytes */
    protected static function decode(string $value, int $maxBytes): string {
        $decodedValue = @gzuncompress($value, $maxBytes);
        if ($decodedValue === false) {
            if (($tmpFile = tempnam(sys_get_temp_dir(), 'gz')) === false) {
                throw new RuntimeException('Unable to create temporary file');
            }

            try {
                if (file_put_contents($tmpFile, "\x1f\x8b\x08\x00\x00\x00\x00\x00" . $value) === false) {
                    throw new RuntimeException('Unable to write data to be uncompressed to temporary file');
                }

                $decodedValue = file_get_contents('compress.zlib://' . $tmpFile, length: $maxBytes + 1);
                if (in_array($decodedValue, ['', false], true)) {
                    throw new ParseFailureException('Unable to gzuncompress value "' . substr(trim($value), 0, 30) . '..."');
                }
            } finally {
                is_file($tmpFile) && unlink($tmpFile);
            }
        }

        if (strlen($decodedValue) > $maxBytes) {
            throw new ParseFailureException('Unable to gzuncompress value as the resulting decoded value was larger than a gigabyte');
        }

        return $decodedValue;
    }
}
