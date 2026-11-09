<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryFactory;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\FilterNameValue;
use PrinsFrank\PdfParser\Document\Filter\Decode\FlateDecode;
use PrinsFrank\PdfParser\Document\Filter\Decode\LZWFlatePredictorValue;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

#[CoversClass(FlateDecode::class)]
#[CoversClass(FilterNameValue::class)]
class PredictorGeometryTest extends TestCase {
    /** @return iterable<string, array{int, int, int, int, string, string}> */
    public static function rows(): iterable {
        yield 'RGB None' => [15, 2, 3, 8, '000a141e0f192300141e28202832', '0a141e0f1923141e28202832'];
        yield 'RGB Sub' => [15, 2, 3, 8, '010a141e05050501141e280a0a0a', '0a141e0f1923141e281e2832'];
        yield 'RGB Up' => [15, 2, 3, 8, '020a141e0f1923020a0a0a0f0f0f', '0a141e0f1923141e281e2832'];
        yield 'RGB Average' => [15, 2, 3, 8, '030a141e0a0f14030f14190d0d0d', '0a141e0f1923141e281e2832'];
        yield 'RGB Paeth' => [15, 2, 3, 8, '040a141e050505040a0a0a0a0a0a', '0a141e0f1923141e281e2832'];
        yield '1-bit padded row' => [15, 9, 1, 1, '01aa01', 'aaab'];
        yield '4-bit RGB byte stride' => [15, 2, 3, 4, '01123444', '123456'];
        yield '16-bit RGB byte stride' => [15, 2, 3, 16, '01000a0014001e000500050005', '000a0014001e000f00190023'];
    }

    #[DataProvider('rows')]
    public function testFlateAndLzwUseIdenticalSampleGeometry(int $predictor, int $columns, int $colors, int $bits, string $encoded, string $expected): void {
        $bytes = hex2bin($encoded);
        static::assertNotFalse($bytes);
        $dictionary = DictionaryFactory::fromArray(null, null, ['/DecodeParms' => [
            '/Predictor' => (string) $predictor, '/Columns' => (string) $columns, '/Colors' => (string) $colors, '/BitsPerComponent' => (string) $bits,
        ]]);
        $compressed = gzcompress($bytes);
        static::assertNotFalse($compressed);
        static::assertSame($expected, bin2hex(FilterNameValue::FLATE_DECODE->decodeBinary($compressed, $dictionary)));
        $codes = [256, ...array_map(ord(...), str_split($bytes)), 257];
        $binary = implode('', array_map(static fn(int $code): string => str_pad(decbin($code), 9, '0', STR_PAD_LEFT), $codes));
        $lzw = '';
        foreach (str_split(str_pad($binary, (int) ceil(strlen($binary) / 8) * 8, '0'), 8) as $byte) {
            $lzw .= chr(intval($byte, 2));
        }
        static::assertSame($expected, bin2hex(FilterNameValue::LZW_DECODE->decodeBinary($lzw, $dictionary)));
    }

    public function testIncompleteRowsFail(): void {
        $this->expectException(ParseFailureException::class);
        FlateDecode::applyPredictor("\x01\x00", LZWFlatePredictorValue::PngOptimum, 2, 3, 8);
    }
}
