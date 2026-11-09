<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Rectangle\Rectangle;
use PrinsFrank\PdfParser\Extraction\TextGrouping\TextGrouping;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(PdfParser::class)]
class TransformedFormTextTest extends TestCase {
    /** @return iterable<string, array{string}> */
    public static function matrices(): iterable {
        yield 'upright' => ['1 0 0 1 200 200'];
        yield '90 degrees' => ['0 1 -1 0 200 200'];
        yield '180 degrees' => ['-1 0 0 -1 200 200'];
        yield '270 degrees' => ['0 -1 1 0 200 200'];
        yield 'horizontal reflection' => ['-1 0 0 1 200 200'];
        yield 'vertical reflection' => ['1 0 0 -1 200 200'];
        yield 'shear and rotation' => ['1 .5 -.25 1 200 200'];
        yield 'negative shear and reflection' => ['-1 -.5 -.25 1 200 200'];
    }

    #[DataProvider('matrices')]
    public function testReadingOrderUsesTheFormBasis(string $matrix): void {
        $document = (new PdfParser())->parseString($this->pdf($matrix));
        static::assertSame("Hello world\nSecond", $document->getText());
        static::assertSame("Hello world\nSecond", trim($document->getMarkdown()));
        $page = $document->getPages()[0];
        $original = $page->getPositionedTextElements();
        static::assertCount(4, $original);
        $fontCache = [];
        $groups = TextGrouping::group($original, $page, $fontCache);
        $matrices = array_map(static fn($element) => $element->absoluteMatrix, $original);
        foreach ($groups as $line) {
            foreach ($line as $element) {
                static::assertContains($element->absoluteMatrix, $matrices);
                static::assertSame('Courier', $element->getFont($page, $fontCache)->getBaseFont());
                $x = $element->absoluteMatrix->offsetX;
                $y = $element->absoluteMatrix->offsetY;
                static::assertTrue($element->startsWithinRectangle(new Rectangle($x - 1, $y - 1, $x + 1, $y + 1)));
                static::assertGreaterThan(0, $element->getHeight());
            }
        }
    }

    public function testMixedOrientationsRemainSeparateAndKeepTheirFontResources(): void {
        $document = (new PdfParser())->parseString($this->pdf('0 1 -1 0 200 200', true));
        static::assertSame("Page\nHello world\nSecond", $document->getText());
        static::assertSame("**Page**\nHello world\nSecond", trim($document->getMarkdown()));
    }

    public function testNearlyParallelShearedGlyphsStayOnOneLine(): void {
        $content = 'BT /F1 12 Tf 1 .0000002 .25 1 60 120 Tm (world) Tj ET '
            . 'BT /F1 12 Tf 20 120 Td (Hello) Tj ET';
        $document = (new PdfParser())->parseString($this->pdf('0 1 -1 0 200 200', content: $content));
        static::assertSame('Hello world', $document->getText());
        static::assertSame('Hello world', trim($document->getMarkdown()));
    }

    #[DataProvider('matrices')]
    public function testNegativeHorizontalScalingReversesTheReadingBasis(string $matrix): void {
        $content = 'BT /F1 12 Tf -100 Tz 60 120 Td (world) Tj ET '
            . 'BT /F1 12 Tf 100 120 Td (Hello) Tj ET';
        $document = (new PdfParser())->parseString($this->pdf($matrix, content: $content));
        static::assertSame('Hello world', $document->getText());
        static::assertSame('Hello world', trim($document->getMarkdown()));
        foreach ($document->getPages()[0]->getPositionedTextElements() as $element) {
            static::assertGreaterThan(0, $element->getHeight());
        }
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function signedTextStates(): iterable {
        foreach (self::matrices() as $name => [$matrix]) {
            foreach ([-12, 12] as $size) {
                foreach ([-100, 100] as $scale) {
                    yield $name . ' size ' . $size . ' scale ' . $scale => [$matrix, $size, $scale];
                }
            }
        }
    }

    #[DataProvider('signedTextStates')]
    public function testFontSizeAndHorizontalScaleSignsDetermineBothReadingAxes(string $matrix, int $size, int $scale): void {
        $directionX = $size * $scale < 0 ? -1 : 1;
        $directionY = $size < 0 ? -1 : 1;
        $prefix = 'BT /F1 ' . $size . ' Tf ' . $scale . ' Tz ';
        $content = $prefix . (20 * $directionX) . ' ' . (100 * $directionY) . ' Td (Second) Tj ET '
            . $prefix . (60 * $directionX) . ' ' . (120 * $directionY) . ' Td (world) Tj ET '
            . $prefix . (20 * $directionX) . ' ' . (120 * $directionY) . ' Td (Hello) Tj ET';
        $document = (new PdfParser())->parseString($this->pdf($matrix, content: $content));
        static::assertSame("Hello world\nSecond", $document->getText());
        static::assertSame("Hello world\nSecond", trim($document->getMarkdown()));
    }

    public function testFooterPaintedFirstStaysAfterTheMainText(): void {
        $content = 'BT /F1 12 Tf 20 120 Td (Footer) Tj ET';
        $pdf = $this->pdf('0 1 -1 0 200 10', withPageText: true, content: $content);
        $pdf = str_replace('BT /F1 12 Tf 20 700 Td (Page) Tj ET /Rotated Do', '/Rotated Do BT /F1 12 Tf 20 700 Td (Page) Tj ET', $pdf);
        $document = (new PdfParser())->parseString($pdf);
        static::assertSame("Page\nFooter", $document->getText());
        static::assertSame("**Page**\nFooter", trim($document->getMarkdown()));
    }

    public function testLinkHitTestingUsesUnprojectedPageCoordinates(): void {
        $document = (new PdfParser())->parseString($this->pdf('0 1 -1 0 200 200', withLink: true));
        static::assertSame("[Hello](https://example.com) world\nSecond", trim($document->getMarkdown()));
    }

    /** @return iterable<string, array{string}> */
    public static function singularMatrices(): iterable {
        yield 'zero baseline' => ['0 0 0 1 200 200'];
        yield 'collapsed height' => ['1 0 1 0 200 200'];
        yield 'collinear basis' => ['1 1 2 2 200 200'];
    }

    #[DataProvider('singularMatrices')]
    public function testCollapsedTextHasFiniteGeometry(string $matrix): void {
        $document = (new PdfParser())->parseString($this->pdf($matrix));
        $page = $document->getPages()[0];
        $fontCache = [];
        foreach ($page->getPositionedTextElements() as $element) {
            static::assertTrue(is_finite($element->getHeight()));
            static::assertTrue(is_finite($element->getRight($page, $fontCache)));
        }
        static::assertSame('', $document->getText());
        static::assertSame('', trim($document->getMarkdown()));
    }

    private function pdf(string $matrix, bool $withPageText = false, bool $withLink = false, ?string $content = null): string {
        $content ??= 'BT /F1 12 Tf 20 100 Td (Second) Tj ET '
            . 'BT /F1 12 Tf 60 120 Td (world) Tj ET '
            . '/Span << /ActualText () >> BDC BT /F1 12 Tf 20 160 Td (hidden) Tj ET EMC '
            . 'BT /F1 12 Tf 20 120 Td (Hello) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /XObject << /Rotated 5 0 R >> /Font << /F1 7 0 R >> >> /Contents 4 0 R ' . ($withLink ? '/Annots [8 0 R]' : '') . ' >>',
            $this->stream(($withPageText ? 'BT /F1 12 Tf 20 700 Td (Page) Tj ET ' : '') . '/Rotated Do'),
            $this->stream($content, '/Type /XObject /Subtype /Form /BBox [0 0 600 800] /Matrix [' . $matrix . '] /Resources << /Font << /F1 6 0 R >> >>'),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        if ($withLink) {
            $objects[] = '<< /Type /Annot /Subtype /Link /Rect [79 219 81 221] /A << /S /URI /URI (https://example.com) >> >>';
        }
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
        return $pdf . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function stream(string $content, string $dictionary = ''): string {
        return '<< /Length ' . strlen($content) . ' ' . $dictionary . " >>\nstream\n" . $content . "\nendstream";
    }
}
