<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Extraction\SpaceDetection\SpaceDetector;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(SpaceDetector::class)]
class RenderModeSpacingTest extends TestCase {
    #[DataProvider('renderModes')]
    public function testOverlaidFormAndPageTextRemainSeparate(int $formMode, int $pageMode): void {
        $document = (new PdfParser())->parseString($this->pdf('R1', $formMode, 'COR1', $pageMode, 20));
        static::assertSame('R1 COR1', $document->getText());
        static::assertSame('R1 COR1', trim($document->getMarkdown()));
    }

    /** @return iterable<string, array{int, int}> */
    public static function renderModes(): iterable {
        yield 'painted then invisible' => [0, 3];
        yield 'invisible then painted' => [3, 0];
        yield 'painted then clipping only' => [0, 7];
        yield 'clipping only then painted' => [7, 0];
        yield 'painted and clipping then invisible' => [4, 3];
    }

    #[DataProvider('continuousModes')]
    public function testContinuousWordDoesNotGainASpace(int $formMode, int $pageMode): void {
        $document = (new PdfParser())->parseString($this->pdf('over', $formMode, 'lap', $pageMode, 48.5));
        static::assertSame('overlap', $document->getText());
        static::assertSame('overlap', trim($document->getMarkdown()));
    }

    /** @return iterable<string, array{int, int}> */
    public static function continuousModes(): iterable {
        yield 'painted kerned word' => [0, 0];
        yield 'fill to stroke' => [0, 1];
        yield 'OCR word' => [3, 3];
        yield 'nonpainting modes' => [3, 7];
    }

    private function pdf(string $formText, int $formMode, string $pageText, int $pageMode, float $pageX): string {
        $pageContent = '/Label Do BT /F1 12 Tf ' . $pageMode . ' Tr ' . $pageX . ' 40 Td (' . $pageText . ') Tj ET';
        $formContent = 'BT /F1 12 Tf ' . $formMode . ' Tr (' . $formText . ') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 6 0 R >> /XObject << /Label 5 0 R >> >> /Contents 4 0 R >>',
            $this->stream($pageContent),
            $this->stream($formContent, '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Matrix [1 0 0 1 20 40] /Resources << /Font << /F1 6 0 R >> >>'),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function stream(string $content, string $dictionary = ''): string {
        return '<< /Length ' . strlen($content) . ' ' . $dictionary . " >>\nstream\n" . $content . "\nendstream";
    }
}
