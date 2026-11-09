<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Extraction\TextGrouping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\Extraction\TextGrouping\TextGrouping;

#[CoversClass(TextGrouping::class)]
class ReflectedOverlayTextGroupingTest extends TestCase {
    public function testReflectedBackgroundDoesNotInterleaveOverlayWords(): void {
        $elements = [
            self::element('Ad', 20, 100, 20, 42),
            self::element('heading', 50, 100, 30, 42),
            self::element('Reflection', 20, 128, 20, -40),
            self::element('copy', 50, 128, 30, -40),
            self::element('Play', 300, 118, 20, -10),
            self::element('Cookie', 10, 110, 20, 10),
            self::element('choices', 90, 110, 30, 10),
            self::element('Body', 10, 90, 80, 10),
        ];
        $fonts = ['F1' => new Font(new Dictionary(null))];
        $lines = TextGrouping::group($elements, new Page(new Dictionary(null)), $fonts);
        $labels = array_map(static fn(array $line): array => array_map(static fn(PositionedTextElement $element): ?string => $element->actualText, $line), $lines);

        static::assertContains(['Cookie', 'choices'], $labels);
        static::assertContains(['Ad', 'heading'], $labels);
        static::assertEqualsCanonicalizing(
            ['Ad', 'heading', 'Reflection', 'copy', 'Play', 'Cookie', 'choices', 'Body'],
            array_merge(...$labels),
        );
    }

    public function testHigherSuperscriptStillGroupsWithLargerBaseAtSameTop(): void {
        $elements = [
            self::element('x', 10, 100, 12, 12),
            self::element('2', 22, 106, 4, 6),
            self::element('Reflection', 300, 50, 20, -10),
        ];
        $fonts = ['F1' => new Font(new Dictionary(null))];
        $lines = TextGrouping::group($elements, new Page(new Dictionary(null)), $fonts);
        static::assertCount(2, $lines);
        static::assertSame(['x', '2'], array_map(static fn(PositionedTextElement $element): ?string => $element->actualText, $lines[0]));
    }

    private static function element(string $label, float $x, float $y, float $width, float $height): PositionedTextElement {
        return new PositionedTextElement(
            [new TextSegment(new LiteralStringValue(''), -100 * $width)],
            new TransformationMatrix(1, 0, 0, $height / 10, $x, $y),
            new TextState(new NameObjectStringValue('F1'), 10),
            actualText: $label,
        );
    }
}
