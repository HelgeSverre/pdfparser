<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(PdfParser::class)]
class ActualTextTest extends TestCase {
    /** @param list<string> $streams */
    #[DataProvider('replacementCases')]
    public function testActualTextReplacesMarkedContent(array $streams, string $expected, string $properties = ''): void {
        $document = (new PdfParser())->parseString($this->pdf($streams, $properties));
        static::assertSame($expected, $document->getText());
        static::assertStringNotContainsString("\0", $document->getText());
        static::assertSame($expected, trim($document->getMarkdown()));
    }

    /** @return iterable<string, array{list<string>, string, 2?: string}> */
    public static function replacementCases(): iterable {
        yield 'consecutive replacements across a gap' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one) >> BDC (x) Tj EMC 100 0 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'onetwo'];
        yield 'consecutive replacements across lines' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one) >> BDC (x) Tj EMC 0 -20 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'onetwo'];
        yield 'replacement supplies its own word break' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one ) >> BDC (x) Tj EMC 100 0 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'one two'];
        yield 'ordinary text breaks replacement adjacency' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one) >> BDC (x) Tj EMC 100 0 Td (visible) Tj 100 0 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'one visible two'];
        yield 'painted graphics break replacement adjacency' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one) >> BDC (x) Tj EMC ET 0 0 10 10 re f BT /F1 12 Tf 120 40 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'one two'];
        yield 'empty scope preserves adjacent characters' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one) >> BDC (x) Tj EMC /Span << /ActualText () >> BDC EMC 100 0 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'onetwo'];
        yield 'empty replacement preserves adjacent characters' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (one) >> BDC (x) Tj EMC 100 0 Td /Span << /ActualText () >> BDC (x) Tj EMC 100 0 Td /Span << /ActualText (two) >> BDC (x) Tj EMC ET',
        ], 'onetwo'];
        yield 'PDF whitespace after the tag' => [[
            "BT /F1 12 Tf 20 40 Td /Span\x00\t\n\f\r << /ActualText (replacement) >> BDC (x) Tj EMC ET",
        ], 'replacement'];
        yield 'adjacent hex and dictionary terminators' => [[
            'BT /F1 12 Tf 20 40 Td /Span<</ActualText<FEFF002D>>>BDC (x) Tj EMC ET',
        ], '-'];
        yield 'null-mapped glyphs' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText <FEFF002D> >> BDC (x) Tj EMC 6 0 Td /Span << /ActualText <FEFF002B> >> BDC (x) Tj EMC ET',
        ], '-+'];
        yield 'multiple operators and text objects' => [[
            '/Span << /ActualText (replacement) >> BDC BT /F1 12 Tf 20 40 Td (x) Tj 6 0 Td [(x) 20 (x)] TJ ET BT /F1 12 Tf 38 40 Td (x) Tj ET EMC',
        ], 'replacement'];
        yield 'replacement spans lines' => [[
            '/Span << /ActualText (replacement) >> BDC BT /F1 12 Tf 20 40 Td (x) Tj ET BT /F1 12 Tf 20 20 Td (x) Tj ET EMC',
        ], 'replacement'];
        yield 'suppressed first line' => [[
            '/Span << /ActualText () >> BDC BT /F1 12 Tf 20 60 Td (x) Tj ET EMC BT /F1 12 Tf 20 40 Td (visible) Tj ET',
        ], 'visible'];
        yield 'scope crosses streams' => [[
            '/Span << /ActualText (replacement) >> BDC BT /F1 12 Tf 20 40 Td (x) Tj ET ',
            'BT /F1 12 Tf 26 40 Td (x) Tj ET EMC ',
        ], 'replacement'];
        yield 'outer replacement wins' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (outer) >> BDC /Span << /ActualText (inner) >> BDC (x) Tj EMC 6 0 Td (x) Tj EMC ET',
        ], 'outer'];
        yield 'unreplaced nesting' => [[
            'BT /F1 12 Tf 20 40 Td /P BMC /Span << /ActualText (one) >> BDC /Span BMC (x) Tj EMC EMC 6 0 Td /Span << /ActualText (two) >> BDC (x) Tj EMC EMC ET',
        ], 'onetwo'];
        yield 'empty replacement' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText () >> BDC (x) Tj EMC 6 0 Td (visible) Tj ET',
        ], 'visible'];
        yield 'escaped UTF16 literal' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (\376\377\000\055) >> BDC (x) Tj EMC ET',
        ], '-'];
        yield 'escaped punctuation and percent' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (A\(B\) 100% / C >>) >> BDC (x) Tj EMC ET',
        ], 'A(B) 100% / C >>'];
        yield 'nested property dictionaries' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /Layout << /Foo 1 >> /ActualText (nested) >> BDC (x) Tj EMC ET',
        ], 'nested'];
        yield 'date-like replacement remains text' => [[
            'BT /F1 12 Tf 20 40 Td /Span << /ActualText (D:20260101) >> BDC (x) Tj EMC ET',
        ], 'D:20260101'];
        yield 'named properties' => [[
            'BT /F1 12 Tf 20 40 Td /Span /P1 BDC (x) Tj EMC ET',
        ], 'named', '/Properties << /P1 << /ActualText (named) >> >>'];
    }

    /** @param list<string> $streams */
    private function pdf(array $streams, string $properties): string {
        $contents = implode(' ', array_map(static fn(int $index): string => ($index + 6) . ' 0 R', array_keys($streams)));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 4 0 R >> ' . $properties . ' >> /Contents [' . $contents . '] >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /ToUnicode 5 0 R >>',
            $this->stream("1 begincodespacerange\n<00> <ff>\nendcodespacerange\n2 beginbfrange\n<00> <77> <0000>\n<79> <ff> <0079>\nendbfrange\n1 beginbfchar\n<78> <0000>\nendbfchar"),
        ];
        foreach ($streams as $stream) {
            $objects[] = $this->stream($stream);
        }
        $pdf = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function stream(string $content): string {
        return '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
}
