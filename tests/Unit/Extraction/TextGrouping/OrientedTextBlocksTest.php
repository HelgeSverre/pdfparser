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
use PrinsFrank\PdfParser\Extraction\TextGrouping\OrientedTextBlocks;
use PrinsFrank\PdfParser\Extraction\TextGrouping\TextGrouping;
use PrinsFrank\PdfParser\Extraction\TextGrouping\TextBoundsIndex;
use PrinsFrank\PdfParser\Tests\Support\ReferenceOrientedTextBlocks;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(OrientedTextBlocks::class)]
#[CoversClass(TextGrouping::class)]
#[CoversClass(TextBoundsIndex::class)]
class OrientedTextBlocksTest extends TestCase {
    public function testCollapsedFirstElementDoesNotMoveRightColumnFooter(): void {
        $elements = [];
        $expected = [];
        for ($i = 0; $i < 30; $i++) {
            $elements[] = self::element('L' . $i, 0, 700 - $i * 20, 100);
            $elements[] = self::element('R' . $i, 400, 700 - $i * 20, 100);
            $expected[] = 'L' . $i;
        }
        for ($i = 0; $i < 30; $i++) {
            $expected[] = 'R' . $i;
        }
        $expected[] = 'footer';
        $elements[] = new PositionedTextElement([new TextSegment(new LiteralStringValue(''), -10000)], new TransformationMatrix(0, 1, -1, 0, 450, 90), new TextState(new NameObjectStringValue('F1'), 10), actualText: 'footer');
        $collapsed = new PositionedTextElement([new TextSegment(new LiteralStringValue(''), -10000)], new TransformationMatrix(0, 0, 0, 1, 0, 900), new TextState(new NameObjectStringValue('F1'), 10), actualText: '');
        $page = new Page(new Dictionary(null));
        foreach ([$elements, [$collapsed, ...$elements]] as $input) {
            $fonts = ['F1' => new Font(new Dictionary(null))];
            $result = [];
            foreach (TextGrouping::group($input, $page, $fonts) as $line) {
                foreach ($line as $element) {
                    if ($element->actualText !== '') {
                        $result[] = $element->actualText;
                    }
                }
            }
            static::assertSame($expected, $result);
        }
    }

    public function testSpatialGroupingMatchesFrozenAllPairsReference(): void {
        $random = new Randomizer(new Mt19937(72813));
        $page = new Page(new Dictionary(null));
        $cases = [
            [],
            [[self::element('first', 0, 0, 10)], [self::element('second', 30, 0, 10)], [self::element('bridge', 15, 0, 10)]],
            [[self::element('large', -100, -100, 400, 50)], [self::element('touching', 350, -100, 10)], [self::element('outside', 411, -100, 10)]],
            [[self::element('same run', -100, -100, 10), self::element('same run', -85, -100, 10), self::element('separate run', 100, -100, 10)]],
        ];
        for ($case = 0; $case < 20; $case++) {
            $lines = [];
            for ($i = 0; $i < 80; $i++) {
                $lines[] = [self::element((string) $i, $random->getInt(-200, 800), $random->getInt(-200, 800), $random->getInt(0, 150), $random->getInt(0, 40))];
            }
            $cases[] = $lines;
        }
        foreach ($cases as $lines) {
            $fonts = ['F1' => new Font(new Dictionary(null))];
            static::assertSame(ReferenceOrientedTextBlocks::group($lines, $page, $fonts), OrientedTextBlocks::group($lines, $page, $fonts));
        }
    }

    public function testIndexRejectsDistantBoundsButRetainsTouchingEdges(): void {
        $index = TextBoundsIndex::build([[0.0, 10.0, 0.0, 10.0], [100.0, 110.0, 100.0, 110.0], [200.0, 210.0, 200.0, 210.0]]);
        static::assertNotNull($index);
        static::assertSame([], iterator_to_array($index->overlappingBefore([20.0, 30.0, 20.0, 30.0], 3), false));
        static::assertSame([0], iterator_to_array($index->overlappingBefore([10.0, 20.0, 10.0, 20.0], 3), false));
        static::assertSame([], iterator_to_array($index->overlappingBefore([0.0, 210.0, 0.0, 210.0], 0), false));
    }

    private static function element(string $label, float $x, float $y, float $width, float $height = 10): PositionedTextElement {
        return new PositionedTextElement([new TextSegment(new LiteralStringValue(''), -100 * $width)], new TransformationMatrix(1, 0, 0, $height / 10, $x, $y), new TextState(new NameObjectStringValue('F1'), 10), actualText: $label);
    }
}
