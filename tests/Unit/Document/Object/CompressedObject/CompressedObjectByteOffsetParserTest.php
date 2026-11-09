<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Object\CompressedObject;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryFactory;
use PrinsFrank\PdfParser\Document\Object\CompressedObject\CompressedObjectByteOffsetParser;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(CompressedObjectByteOffsetParser::class)]
class CompressedObjectByteOffsetParserTest extends TestCase {
    /** @return iterable<string, array{string}> */
    public static function integerSpellings(): iterable {
        yield 'explicit positive signs' => ['+12 +0 '];
        yield 'leading zeroes' => ['0012 000 '];
        yield 'negative zero offset' => ['12 -0 '];
    }

    #[DataProvider('integerSpellings')]
    public function testHeaderIntegersFollowPdfNumberSyntax(string $header): void {
        $content = $header . '<<>>';
        $source = "stream\n" . $content . "\nendstream";
        $dictionary = DictionaryFactory::fromArray(null, null, ['/Length' => (string) strlen($content), '/First' => (string) strlen($header)]);
        $offsets = CompressedObjectByteOffsetParser::parse(new InMemoryStream($source), 0, strlen($source), $dictionary);
        static::assertSame(0, $offsets->getRelativeByteOffsetForObject(12));
    }

    public function testHeaderOffsetsUsePdfWhitespaceAndStopAtFirstObject(): void {

        $header = "12\x00 0\t34\r7\n56\f14 ";
        $content = $header . '(first)(other)(third)';
        $source = "stream\n" . $content . "\nendstream";
        $dictionary = DictionaryFactory::fromArray(null, null, ['/Length' => (string) strlen($content), '/First' => (string) strlen($header)]);
        $offsets = CompressedObjectByteOffsetParser::parse(new InMemoryStream($source), 0, strlen($source), $dictionary);
        static::assertSame(0, $offsets->getRelativeByteOffsetForObject(12));
        static::assertSame(7, $offsets->getRelativeByteOffsetForObject(34));
        static::assertSame(14, $offsets->getRelativeByteOffsetForObject(56));
        static::assertNull($offsets->getRelativeByteOffsetForObject(1));
    }
}
