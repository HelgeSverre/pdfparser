<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Support;

use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;

/** Frozen all-pairs reference for differential tests of the spatial index. */
class ReferenceOrientedTextBlocks {
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

        $parents = array_keys($runs);
        foreach ($bounds as $i => [$left, $right, $bottom, $top, $height]) {
            for ($j = 0; $j < $i; $j++) {
                [$otherLeft, $otherRight, $otherBottom, $otherTop, $otherHeight] = $bounds[$j];
                $tolerance = max($height, $otherHeight);
                if (max($left, $otherLeft) - min($right, $otherRight) > $tolerance
                    || max($bottom, $otherBottom) - min($top, $otherTop) > $tolerance) {
                    continue;
                }
                $root = $j;
                while ($parents[$root] !== $root) {
                    $root = $parents[$root];
                }
                $currentRoot = $i;
                while ($parents[$currentRoot] !== $currentRoot) {
                    $currentRoot = $parents[$currentRoot];
                }
                $parents[$currentRoot] = $root;
            }
        }
        $blocks = [];
        foreach ($runs as $i => $run) {
            $root = $i;
            while ($parents[$root] !== $root) {
                $root = $parents[$root];
            }
            $blocks[$root][] = $run;
        }
        return array_values($blocks);
    }
}
