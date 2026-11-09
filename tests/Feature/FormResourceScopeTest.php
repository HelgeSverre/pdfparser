<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(PdfParser::class)]
class FormResourceScopeTest extends TestCase {
    /** @return iterable<string, array{string}> */
    public static function formMetadata(): iterable {
        yield 'no private metadata' => [''];
        yield 'Adobe FillSign metadata' => ['/ADBE_FillSign << /Type /FillSignData /Subtype /text /Size [55.044 14.641] /Text 1234567 >>'];
    }

    #[DataProvider('formMetadata')]
    public function testNestedFormsUseTheirOwnFontsAndMatrices(string $metadata): void {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /XObject << /Outer 5 0 R >> >> /Contents 4 0 R >>',
            $this->stream('q /Outer Do Q'),
            $this->stream('/Inner Do', '/Type /XObject /Subtype /Form /BBox [0 0 600 800] /Resources << /XObject << /Inner 6 0 R >> >>'),
            $this->stream('BT /F1 12 Tf 20 40 Td (Nested text) Tj ET', '/Type /XObject /Subtype /Form /BBox [0 0 600 800] /Matrix [1 0 0 1 10 15] /Resources << /Font << /F1 7 0 R >> >> ' . $metadata),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 8\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        $document = (new PdfParser())->parseString($pdf);
        static::assertSame('Nested text', $document->getText());
        static::assertStringContainsString('Nested text', $document->getMarkdown());
    }

    private function stream(string $content, string $dictionary = ''): string {
        return '<< /Length ' . strlen($content) . ' ' . $dictionary . " >>\nstream\n" . $content . "\nendstream";
    }
}
