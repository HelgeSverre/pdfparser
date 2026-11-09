<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\CMap\ToUnicode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\CMap\ToUnicode\ToUnicodeCMap;
use PrinsFrank\PdfParser\Document\CMap\ToUnicode\ToUnicodeCMapParser;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(ToUnicodeCMap::class)]
#[CoversClass(ToUnicodeCMapParser::class)]
class VariableWidthCMapTest extends TestCase {
    private function parse(string $ranges, string $mappings): ToUnicodeCMap {
        $stream = new InMemoryStream("begincodespacerange\n$ranges\nendcodespacerange\n$mappings");
        return ToUnicodeCMapParser::parse($stream, 0, $stream->getSizeInBytes());
    }

    public function testMixedWidthsAndRepeatedIntegerCodesDoNotShareMappingsOrCache(): void {
        $cmap = $this->parse("<01><7f>\n<0000><00ff>\n<8140><9ffc>", "beginbfchar\n<01><0041>\n<0001><0042>\n<8140><0043>\n<9ffc><0044>\nendbfchar");
        static::assertSame('ABCDAB', $cmap->textToUnicode("\x01\x00\x01\x81\x40\x9f\xfc\x01\x00\x01"));
    }

    public function testVariableWidthRangesAndUnmappedCharactersPreserveBoundaries(): void {
        $cmap = $this->parse("<20><7f>\n<8100><81ff>", "beginbfrange\n<20><22><0041>\n<8100><8102>[<0058><0059><005a>]\nendbfrange");
        static::assertSame('ABXYZC', $cmap->textToUnicode("\x20\x21\x81\x00\x81\x01\x81\x02\x81\xff\x22"));
    }

    public function testCodespacesCompareEachByteInsteadOfIntegerIntervals(): void {
        $cmap = $this->parse("<20><7f>\n<8140><9ffc>", "beginbfchar\n<8210><0041>\nendbfchar");
        $this->expectException(ParseFailureException::class);
        $cmap->textToUnicode("\x82\x10");
    }

    public function testIssue215FixedWidthMapUsesExplicitMappingOutsideRectangularCodespace(): void {
        $cmap = $this->parse('<0000><0bd0>', "beginbfchar\n<00f0><00b2>\nendbfchar");
        static::assertSame('²', $cmap->textToUnicode("\x00\xf0"));
    }

    public function testTruncatedMultibyteCodeIsRejected(): void {
        $cmap = $this->parse("<20><7f>\n<8100><81ff>", '');
        $this->expectException(ParseFailureException::class);
        $cmap->textToUnicode("\x81");
    }

    public function testTruncatedFixedWidthCodeIsRejected(): void {
        $cmap = $this->parse('<0000><ffff>', '');
        $this->expectException(ParseFailureException::class);
        $cmap->textToUnicode("\x81");
    }

    public function testCodespaceLongerThanFourBytesIsRejected(): void {
        $this->expectException(ParseFailureException::class);
        $this->parse('<0000000000><ffffffffff>', '');
    }

    public function testNonHexadecimalMappingIsRejected(): void {
        $this->expectException(ParseFailureException::class);
        $this->parse('<00><ff>', "beginbfchar\n<41><00gg>\nendbfchar");
    }

    public function testThreeAndFourByteCodesAndRangeCacheWidths(): void {
        $cmap = $this->parse("<01><7f>\n<000000><00ffff>\n<80000000><80ffffff>", "beginbfrange\n<01><02><0041>\n<000001><000002><0058>\n<80000000><80000001><005a>\nendbfrange");
        static::assertSame('AXBYZ[', $cmap->textToUnicode("\x01\x00\x00\x01\x02\x20\x00\x00\x02\x80\x00\x00\x00\x80\x00\x00\x01"));
    }

    public function testBFCharWhitespaceDoesNotChangeSourceWidth(): void {
        $cmap = $this->parse('<0000><ffff>', "beginbfchar\n<00  41><0041>\n<00\0\t\n\f\r 42><00\t 42>\nendbfchar");
        static::assertSame('AB', $cmap->textToUnicode("\x00\x41\x00\x42"));
    }

    public function testBFRangeWhitespaceDoesNotChangeSourceWidth(): void {
        $cmap = $this->parse('<0000><ffff>', "beginbfrange\n<00  41><00\t42><00 41>\n<00\0\n43><00\r\f44>[<00 43><00\t44>]\nendbfrange");
        static::assertSame('ABCD', $cmap->textToUnicode("\x00\x41\x00\x42\x00\x43\x00\x44"));
    }

    public function testCodeSpaceWhitespacePreservesMixedWidths(): void {
        $cmap = $this->parse("<0 1><7 f>\n<00\0\t\n\f\r 00><00 ff>", "beginbfchar\n<01><0041>\n<00  01><0042>\nendbfchar");
        static::assertSame('ABAB', $cmap->textToUnicode("\x01\x00\x01\x01\x00\x01"));
    }

    public function testOddHexStringsPadTheirFinalLowNibble(): void {
        $cmap = $this->parse('<0><f>', "beginbfchar\n<4><004>\nendbfchar\nbeginbfrange\n<5><51>[<005><006>]\nendbfrange");
        static::assertSame('@P`', $cmap->textToUnicode("\x40\x50\x51"));
    }
}
