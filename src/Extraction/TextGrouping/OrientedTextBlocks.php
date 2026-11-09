<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Extraction\TextGrouping;

use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;

/** @internal */
class OrientedTextBlocks {
    /**
     * @param list<list<PositionedTextElement>> $lines
     * @param array<string, Font> $fontCache
     * @return list<list<list<PositionedTextElement>>>
     */
    public static function group(array $lines, Page $page, array &$fontCache): array {
        $runs = [];
        foreach ($lines as $line) {
            $run = [];
            $right = -INF;
            $em = 0.0;
            foreach ($line as $element) {
                $matrix = $element->getLayoutMatrix();
                $elementEm = abs($element->textState->getFontSize() * $matrix->scaleX * $element->textState->scale / 100);
                if ($run !== [] && $matrix->offsetX - $right > 3 * max($em, $elementEm)) {
                    $runs[] = $run;
                    $run = [];
                }
                $run[] = $element;
                $right = $element->getLayoutRight($page, $fontCache);
                $em = $elementEm;
            }
            if ($run !== []) {
                $runs[] = $run;
            }
        }

        $bounds = [];
        foreach ($runs as $run) {
            $left = $bottom = INF;
            $right = $top = -INF;
            $height = 0.0;
            foreach ($run as $element) {
                $matrix = $element->getLayoutMatrix();
                $left = min($left, $matrix->offsetX);
                $right = max($right, $element->getLayoutRight($page, $fontCache));
                $bottom = min($bottom, $matrix->offsetY);
                $top = max($top, $matrix->offsetY + $element->getHeight());
                $height = max($height, $element->getHeight());
            }
            $bounds[] = [$left, $right, $bottom, $top, $height];
        }

        // Expanding both boxes is a conservative query; the original max-height rule remains the exact check.
        $expandedBounds = array_map(
            static fn(array $bound): array => [min($bound[0], $bound[1]) - $bound[4], max($bound[0], $bound[1]) + $bound[4], min($bound[2], $bound[3]) - $bound[4], max($bound[2], $bound[3]) + $bound[4]],
            $bounds,
        );
        $index = TextBoundsIndex::build($expandedBounds);
        $parents = array_keys($runs);
        $ranks = array_fill(0, count($runs), 0);
        foreach ($bounds as $i => [$left, $right, $bottom, $top, $height]) {
            foreach ($index?->overlappingBefore($expandedBounds[$i], $i) ?? [] as $j) {
                [$otherLeft, $otherRight, $otherBottom, $otherTop, $otherHeight] = $bounds[$j];
                $tolerance = max($height, $otherHeight);
                if (max($left, $otherLeft) - min($right, $otherRight) > $tolerance
                    || max($bottom, $otherBottom) - min($top, $otherTop) > $tolerance) {
                    continue;
                }
                $root = self::root($j, $parents);
                $currentRoot = self::root($i, $parents);
                if ($root === $currentRoot) {
                    continue;
                }
                if ($ranks[$root] < $ranks[$currentRoot]) {
                    [$root, $currentRoot] = [$currentRoot, $root];
                }
                $parents[$currentRoot] = $root;
                if ($ranks[$root] === $ranks[$currentRoot]) {
                    $ranks[$root]++;
                }
            }
        }
        $blocks = [];
        foreach ($runs as $i => $run) {
            $root = self::root($i, $parents);
            $blocks[$root][] = $run;
        }
        return array_values($blocks);
    }

    /** @param array<int, int> $parents */
    private static function root(int $index, array &$parents): int {
        $root = $index;
        while ($parents[$root] !== $root) {
            $root = $parents[$root];
        }
        while ($parents[$index] !== $index) {
            $next = $parents[$index];
            $parents[$index] = $root;
            $index = $next;
        }
        return $root;
    }
}
