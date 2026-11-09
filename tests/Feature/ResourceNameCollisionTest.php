<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(PdfParser::class)]
class ResourceNameCollisionTest extends TestCase {
    /** @return iterable<string, array{string}> */
    public static function fontNames(): iterable {
        yield 'standard dictionary name' => ['Font'];
        yield 'custom resource name' => ['F1'];
    }

    #[DataProvider('fontNames')]
    public function testFormAndFontResourceNamesMayMatchDictionaryKeys(string $fontName): void {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Resources << /Font << /Type 6 0 R >> /XObject << /Form 5 0 R >> >> /Contents 4 0 R >>',
            self::stream('BT /Type 12 Tf 20 80 Td (Page) Tj ET /Form Do'),
            self::stream('BT /' . $fontName . ' 12 Tf 20 40 Td (Form) Tj ET', '/Type /XObject /Subtype /Form /BBox [0 0 300 300] /Resources << /Font << /' . $fontName . ' 6 0 R >> >>'),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
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
        $page = $document->getPage(1);
        static::assertNotNull($page);
        static::assertSame("Page\nForm", $document->getText());
        static::assertSame('Helvetica', $page->getFont(new NameObjectStringValue('Type'))->getBaseFont());
        static::assertStringContainsString('Form', $document->getMarkdown());
    }

    private static function stream(string $content, string $dictionary = ''): string {
        return '<< /Length ' . strlen($content) . ' ' . $dictionary . " >>\nstream\n" . $content . "\nendstream";
    }
}
