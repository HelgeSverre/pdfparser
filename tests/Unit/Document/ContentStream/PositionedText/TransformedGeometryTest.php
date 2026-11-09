<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\ContentStream\PositionedText;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryEntry\DictionaryEntry;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\SubtypeNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\Extraction\TextGrouping\ReadingOrientation;

#[CoversClass(PositionedTextElement::class)]
#[CoversClass(ReadingOrientation::class)]
class TransformedGeometryTest extends TestCase {
    /** @return iterable<string, array{TransformationMatrix, float, float, float}> */
    public static function geometry(): iterable {
        yield 'upright' => [new TransformationMatrix(2, 0, 0, 3, 100, 200), 36.0, 43.2, 143.2];
        yield 'quarter turn' => [new TransformationMatrix(0, 2, -3, 0, 100, 200), 36.0, 43.2, 100.0];
        yield 'half turn' => [new TransformationMatrix(-2, 0, 0, -3, 100, 200), 36.0, 43.2, 100.0];
        yield 'reflection' => [new TransformationMatrix(-2, 0, 0, 3, 100, 200), 36.0, 43.2, 100.0];
        yield 'shear' => [new TransformationMatrix(2, 1, .5, 3, 100, 200), 66 / sqrt(5), 21.6 * sqrt(5), 143.2];
    }

    public function testSignedFontSizePreservesCharacterWordAndTJDisplacements(): void {
        $font = new Font(new Dictionary(
            null,
            new DictionaryEntry(DictionaryKey::BASE_FONT, new NameObjectStringValue('Courier')),
            new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::TYPE_1),
        ));
        foreach ([-12, 12] as $size) {
            foreach ([-100, 100] as $scale) {
                $element = new PositionedTextElement(
                    [new TextSegment(new LiteralStringValue('A A'), -100)],
                    new TransformationMatrix(1, 0, 0, 1, 100, 200),
                    new TextState(new NameObjectStringValue('F1'), $size, charSpace: 2, wordSpace: 3, scale: $scale),
                );
                // Three .6em glyphs plus .1em TJ displacement; Tc/Tw retain their signs before reading-axis projection.
                static::assertEqualsWithDelta($size < 0 ? 13.8 : 31.8, $element->getAdvanceWidth($font), .0000001);
                static::assertSame(12.0, $element->getHeight());
                $effective = $element->getEffectiveMatrix();
                static::assertSame($size * $scale < 0 ? -1.0 : 1.0, $effective->scaleX);
                static::assertSame($size < 0 ? -1.0 : 1.0, $effective->scaleY);
            }
        }
    }

    #[DataProvider('geometry')]
    public function testPhysicalMetricsAndProjectedLayoutAreSeparate(TransformationMatrix $matrix, float $height, float $advance, float $right): void {
        $font = new Font(new Dictionary(
            null,
            new DictionaryEntry(DictionaryKey::BASE_FONT, new NameObjectStringValue('Courier')),
            new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::TYPE_1),
        ));
        $fontCache = ['F1' => $font];
        $page = $this->createMock(Page::class);
        $element = new PositionedTextElement([new TextSegment(new LiteralStringValue('ABC'), null)], $matrix, new TextState(new NameObjectStringValue('F1'), 12));
        $projected = ReadingOrientation::group([$element])[0][0];
        static::assertSame($matrix, $element->absoluteMatrix);
        static::assertSame($matrix, $projected->absoluteMatrix);
        static::assertEqualsWithDelta($height, $element->getHeight(), 0.0000001);
        static::assertEqualsWithDelta($advance, $element->getAdvanceWidth($font), 0.0000001);
        static::assertEqualsWithDelta($right, $element->getRight($page, $fontCache), 0.0000001);
        static::assertEqualsWithDelta($right, $projected->getRight($page, $fontCache), 0.0000001);
        static::assertEqualsWithDelta($advance, $projected->getLayoutRight($page, $fontCache) - $projected->getLayoutMatrix()->offsetX, 0.0000001);
    }
}
