<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\ContentStream\PositionedText\LineGroupingStrategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Extraction\TextGrouping\LineGrouping\TextOverlapStrategy;

#[CoversClass(TextOverlapStrategy::class)]
class TextOverlapStrategyTest extends TestCase {
    public function testEqualCoordinatesPreserveInputOrderWhenSortingBothAxes(): void {
        $first = new PositionedTextElement([new TextSegment(new LiteralStringValue('z'), null)], new TransformationMatrix(1, 0, 0, 1, 100, 700), new TextState(null, 10));
        $second = new PositionedTextElement([new TextSegment(new LiteralStringValue('a'), null)], new TransformationMatrix(1, 0, 0, 1, 100, 700), new TextState(null, 10));
        $right = new PositionedTextElement([new TextSegment(new LiteralStringValue('right'), null)], new TransformationMatrix(1, 0, 0, 1, 200, 700), new TextState(null, 10));
        $bottom = new PositionedTextElement([new TextSegment(new LiteralStringValue('bottom'), null)], new TransformationMatrix(1, 0, 0, 1, 100, 100), new TextState(null, 10));

        static::assertSame(
            [[$first, $second, $right], [$bottom]],
            iterator_to_array(TextOverlapStrategy::group([$bottom, $right, $first, $second]), false),
        );
        static::assertSame(
            [[$first, $second, $right], [$bottom]],
            iterator_to_array(TextOverlapStrategy::group([$first, $second, $right, $bottom]), false),
        );
    }

    public function testProjectedCoordinatesOrderRotatedText(): void {
        $first = new PositionedTextElement([new TextSegment(new LiteralStringValue('first'), null)], new TransformationMatrix(0, 1, -1, 0, 100, 100), new TextState(null, 10));
        $second = new PositionedTextElement([new TextSegment(new LiteralStringValue('second'), null)], new TransformationMatrix(0, 1, -1, 0, 100, 130), new TextState(null, 10));
        $projected = \PrinsFrank\PdfParser\Extraction\TextGrouping\ReadingOrientation::group([$second, $first])[0];
        static::assertSame([[$projected[1], $projected[0]]], iterator_to_array(TextOverlapStrategy::group($projected), false));
    }

    public function testTallOverlayCanBeSeparatedFromForegroundLine(): void {
        $foreground = new PositionedTextElement([], new TransformationMatrix(1, 0, 0, 1, 0, 100), new TextState(null, 10));
        $background = new PositionedTextElement([], new TransformationMatrix(1, 0, 0, 1, 20, 95), new TextState(null, 40));
        static::assertSame([[$foreground, $background]], iterator_to_array(TextOverlapStrategy::group([$foreground, $background]), false));
        static::assertSame([[$foreground], [$background]], iterator_to_array(TextOverlapStrategy::group([$foreground, $background], true), false));
    }

    public function testOrdersLinesTopToBottom(): void {

        $top = new PositionedTextElement([new TextSegment(new LiteralStringValue('top'), null)], new TransformationMatrix(1, 0, 0, 1, 100, 700), new TextState(null, 10));
        $bottom = new PositionedTextElement([new TextSegment(new LiteralStringValue('bottom'), null)], new TransformationMatrix(1, 0, 0, 1, 100, 100), new TextState(null, 10));

        static::assertSame(
            [[$top], [$bottom]],
            iterator_to_array(TextOverlapStrategy::group([$bottom, $top]), false),
        );
    }

    public function testOrdersLinesTopToBottomOnANegativeOriginPage(): void {
        // A page whose MediaBox origin sits above its content (negative lower-left, e.g. [0 -792 612 0]) has
        // all-negative offsetY, with the topmost line the least negative. Ordering by descending offsetY keeps
        // reading order; ordering by abs(offsetY) -- as the code did before text positions were composed correctly
        // -- would reverse the page.
        $top = new PositionedTextElement([new TextSegment(new LiteralStringValue('top'), null)], new TransformationMatrix(1, 0, 0, 1, 100, -50), new TextState(null, 10));
        $bottom = new PositionedTextElement([new TextSegment(new LiteralStringValue('bottom'), null)], new TransformationMatrix(1, 0, 0, 1, 100, -300), new TextState(null, 10));

        static::assertSame(
            [[$top], [$bottom]],
            iterator_to_array(TextOverlapStrategy::group([$bottom, $top]), false),
        );
    }
}
