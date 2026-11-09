<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(PdfParser::class)]
class SymbolEncodingTest extends TestCase {
    /** @return iterable<string, array{string, string, string}> */
    public static function encodings(): iterable {
        yield 'built-in Symbol encoding' => ['', '4161B4B7', 'Αα×•'];
        yield 'Differences override built-in glyphs' => ['/Encoding << /Differences [65 /A] >>', '4161B4B7', 'Aα×•'];
        yield 'explicit base encoding wins' => ['/Encoding /WinAnsiEncoding', '4161B4B7', 'Aa´·'];
        yield 'base encoding dictionary wins' => ['/Encoding << /BaseEncoding /WinAnsiEncoding >>', '4161B4B7', 'Aa´·'];
        yield 'custom encoding is not replaced' => ['/Encoding /CustomSymbol', '41', 'A'];
        yield 'font descriptor excludes built-in mapping' => ['/FontDescriptor << /Type /FontDescriptor /FontName /Symbol >>', '41', 'A'];
        yield 'explicit ToUnicode wins' => ['/ToUnicode 6 0 R', '41', 'Z'];
        yield 'undefined code stays unchanged' => ['', '01', "\x01"];
    }

    #[DataProvider('encodings')]
    public function testStandardSymbolUsesItsBuiltInEncoding(string $encoding, string $hex, string $expected): void {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            self::stream('BT /F1 12 Tf 20 40 Td <' . $hex . '> Tj ET'),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Symbol ' . $encoding . ' >>',
            self::stream('1 begincodespacerange <00> <ff> endcodespacerange 1 beginbfchar <41> <005A> endbfchar'),
        ];
        $pdf = "%PDF-1.4\n";
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
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        $document = (new PdfParser())->parseString($pdf);
        static::assertSame($expected, $document->getText());
        static::assertStringContainsString($expected, $document->getMarkdown());
    }

    private static function stream(string $content): string {
        return '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
}
