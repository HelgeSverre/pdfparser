<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Filter\Decode\LZWDecode;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

#[CoversClass(LZWDecode::class)]
class LZWDecodeTest extends TestCase {
    public function testDictionaryReferencesIncludingNextCode(): void {
        static::assertSame('ABABABA', LZWDecode::decodeBinary($this->pack([[256,9],[65,9],[66,9],[258,9],[260,9],[257,9]])));
    }

    public function testClearCodeResetsDictionary(): void {
        static::assertSame('ABA', LZWDecode::decodeBinary($this->pack([[256,9],[65,9],[66,9],[256,9],[65,9],[257,9]])));
    }

    /** @return iterable<array{int}> */
    public static function changes(): iterable {
        yield [0];
        yield [1];
    }

    #[DataProvider('changes')]
    public function testBitWidthTransitions(int $early): void {
        $codes = [[256, 9]];
        $expected = '';
        for ($i = 0; $i < 5000; $i++) {
            $width = $i < 255 - $early ? 9 : ($i < 767 - $early ? 10 : ($i < 1791 - $early ? 11 : 12));
            $codes[] = [$i % 256, $width];
            $expected .= chr($i % 256);
        }
        $codes[] = [256, 12];
        $codes[] = [66, 9];
        $expected .= 'B';
        $codes[] = [257, 9];
        static::assertSame($expected, LZWDecode::decodeBinary($this->pack($codes), $early));
    }

    public function testTruncatedStreamIsRejected(): void {
        $this->expectException(ParseFailureException::class);
        LZWDecode::decodeBinary($this->pack([[256, 9], [65, 9]]));
    }

    public function testInvalidDictionaryReferenceIsRejected(): void {
        $this->expectException(ParseFailureException::class);
        LZWDecode::decodeBinary($this->pack([[256,9],[300,9],[257,9]]));
    }

    /** @param list<array{int, int}> $codes */
    private function pack(array $codes): string {
        $bits = '';
        foreach ($codes as [$code, $width]) {
            $bits .= str_pad(decbin($code), $width, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');
        return implode('', array_map(static fn(string $byte): string => chr((int) bindec($byte)), str_split($bits, 8)));
    }
}
