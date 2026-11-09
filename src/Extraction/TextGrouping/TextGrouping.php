<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Extraction\TextGrouping;

use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Extraction\TextGrouping\ColumnGrouping\ColumnGroupingStrategy;
use PrinsFrank\PdfParser\Extraction\TextGrouping\LineGrouping\TextOverlapStrategy;

class TextGrouping {
    /**
     * @param list<PositionedTextElement> $positionedTextElements
     * @param array<string, Font> $fontCache
     * @return list<list<PositionedTextElement>>
     */
    public static function group(array $positionedTextElements, Page $page, array &$fontCache): array {
        $orientations = ReadingOrientation::group($positionedTextElements);
        if (count($orientations) === 1) {
            return self::groupHorizontal($orientations[0], $page, $fontCache);
        }
        $primaryIndex = $primaryVisibleCount = 0;
        foreach ($orientations as $index => $elements) {
            $visibleCount = count(array_filter($elements, static fn(PositionedTextElement $element): bool => $element->actualText !== '' && $element->getHeight() > 0));
            $matrix = $elements[0]->getLayoutMatrix();
            if ($matrix === $elements[0]->getEffectiveMatrix() && $matrix->scaleX >= 0 && $matrix->shearX === 0.0 && $matrix->scaleY >= 0 && $visibleCount > 0) {
                $primaryIndex = $index;
                break;
            }
            if ($visibleCount > $primaryVisibleCount) {
                $primaryIndex = $index;
                $primaryVisibleCount = $visibleCount;
            }
        }
        $primary = $orientations[$primaryIndex];
        $layoutColumns = [];
        $lines = self::groupHorizontal($primary, $page, $fontCache, $layoutColumns, separateOverlays: true);
        $basis = $primary[0];
        foreach ($primary as $element) {
            if ($element->getHeight() > 0) {
                $basis = $element;
                break;
            }
        }
        $blocks = [];
        foreach ($orientations as $index => $elements) {
            if ($index === $primaryIndex) {
                continue;
            }
            foreach (OrientedTextBlocks::group(self::groupHorizontal($elements, $page, $fontCache), $page, $fontCache) as $block) {
                $anchors = [];
                foreach ($block as $line) {
                    foreach ($line as $element) {
                        $anchors[] = self::anchor($element, $basis);
                    }
                }
                sort($anchors, SORT_NUMERIC);
                $blocks[] = ['anchor' => $anchors[intdiv(count($anchors), 2)], 'lines' => $block];
            }
        }
        usort($blocks, static fn(array $a, array $b): int => $b['anchor'] <=> $a['anchor']);
        // Insert marginal text into its nearest existing column without interleaving separate body columns.
        $columnBounds = $lineAnchors = [];
        $lineIndex = 0;
        foreach ($layoutColumns as $column) {
            $start = $lineIndex;
            $left = $bottom = INF;
            $right = $top = -INF;
            foreach ($column as $line) {
                $lineTop = -INF;
                foreach ($line as $element) {
                    $matrix = $element->getLayoutMatrix();
                    $left = min($left, $matrix->offsetX);
                    $right = max($right, $element->getLayoutRight($page, $fontCache));
                    $bottom = min($bottom, $matrix->offsetY);
                    $top = max($top, $matrix->offsetY + $element->getHeight());
                    $lineTop = max($lineTop, $matrix->offsetY);
                }
                $lineAnchors[$lineIndex++] = $lineTop;
            }
            $columnBounds[] = [$start, $lineIndex, $left, $right, $bottom, $top];
        }
        $insertions = [];
        foreach ($blocks as $block) {
            $previousIndex = 0;
            foreach ($block['lines'] as $line) {
                $xs = $ys = [];
                foreach ($line as $element) {
                    $xs[] = self::anchor($element, $basis, true);
                    $ys[] = self::anchor($element, $basis);
                }
                sort($xs, SORT_NUMERIC);
                sort($ys, SORT_NUMERIC);
                $x = $xs[intdiv(count($xs), 2)];
                $y = $ys[intdiv(count($ys), 2)];
                $distance = INF;
                $insertion = count($lines);
                foreach ($columnBounds as [$start, $end, $left, $right, $bottom, $top]) {
                    $candidateDistance = hypot(max($left - $x, $x - $right, 0), max($bottom - $y, $y - $top, 0));
                    if ($candidateDistance >= $distance) {
                        continue;
                    }
                    $distance = $candidateDistance;
                    $insertion = $start;
                    while ($insertion < $end && $lineAnchors[$insertion] > $y) {
                        $insertion++;
                    }
                }
                // Page anchors must not reverse lines belonging to one connected rotated block.
                $previousIndex = max($previousIndex, $insertion);
                $insertions[$previousIndex][] = $line;
            }
        }
        $result = [];
        foreach ($lines as $index => $line) {
            array_push($result, ...($insertions[$index] ?? []));
            $result[] = $line;
        }
        array_push($result, ...($insertions[count($lines)] ?? []));
        return $result;
    }

    private static function anchor(PositionedTextElement $element, PositionedTextElement $basis, bool $horizontal = false): float {
        $matrix = $basis->getEffectiveMatrix();
        $length = hypot($matrix->scaleX, $matrix->shearX);
        if ($length === 0.0) {
            return $horizontal ? $element->absoluteMatrix->offsetX : $element->absoluteMatrix->offsetY;
        }
        if ($horizontal) {
            return ($matrix->scaleX * $element->absoluteMatrix->offsetX + $matrix->shearX * $element->absoluteMatrix->offsetY) / $length;
        }
        $sign = $matrix->scaleX * $matrix->scaleY - $matrix->shearX * $matrix->shearY < 0 ? -1 : 1;
        return ($matrix->scaleX * $element->absoluteMatrix->offsetY - $matrix->shearX * $element->absoluteMatrix->offsetX) / $length * $sign;
    }

    /**
     * @param list<PositionedTextElement> $positionedTextElements
     * @param array<string, Font> $fontCache
     * @param list<list<list<PositionedTextElement>>> $layoutColumns
     * @return list<list<PositionedTextElement>>
     */
    private static function groupHorizontal(array $positionedTextElements, Page $page, array &$fontCache, array &$layoutColumns = [], bool $separateOverlays = false): array {
        $elementsGroupedByLine = $layoutColumns = [];
        $originalLines = null;
        $columns = ColumnGroupingStrategy::group($positionedTextElements, $page, $fontCache, $originalLines, $separateOverlays);
        if (count($columns) === 1 && $columns[0] === $positionedTextElements && $originalLines !== null) {
            $layoutColumns = [$originalLines];
            return $originalLines;
        }
        foreach ($columns as $elementsInColumn) {
            $columnLines = iterator_to_array(TextOverlapStrategy::group($elementsInColumn, $separateOverlays), false);
            $layoutColumns[] = $columnLines;
            foreach ($columnLines as $elementsOnLineInColumn) {
                $elementsGroupedByLine[] = $elementsOnLineInColumn;
            }
        }

        return $elementsGroupedByLine;
    }
}
