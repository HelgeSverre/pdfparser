<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Extraction\TextGrouping;

use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;

/** @internal */
readonly class ReadingOrientation {
    private const DIRECTION_TOLERANCE = 0.000001;

    private function __construct(private float $x, private float $y, private int $handedness) {}

    /**
     * Each orientation is laid out in its own reading basis; nearby directions share one basis to absorb rounding error.
     *
     * @param list<PositionedTextElement> $elements
     * @return list<list<PositionedTextElement>>
     */
    public static function group(array $elements): array {
        $needsProjection = false;
        foreach ($elements as $element) {
            $matrix = $element->getEffectiveMatrix();
            if ($matrix->scaleX < 0 || $matrix->shearX !== 0.0 || $matrix->scaleY < 0) {
                $needsProjection = true;
                break;
            }
        }
        if (!$needsProjection) {
            return [$elements];
        }

        $orientations = $groups = [];
        foreach ($elements as $element) {
            $matrix = $element->getEffectiveMatrix();
            $length = hypot($matrix->scaleX, $matrix->shearX);
            $x = $length === 0.0 ? 1.0 : $matrix->scaleX / $length;
            $y = $length === 0.0 ? 0.0 : $matrix->shearX / $length;
            $handedness = ($matrix->scaleX * $matrix->scaleY - $matrix->shearX * $matrix->shearY) < 0 ? -1 : 1;
            foreach ($orientations as $index => $orientation) {
                if ($orientation->handedness === $handedness
                    && $orientation->x * $x + $orientation->y * $y > 0
                    && abs($orientation->x * $y - $orientation->y * $x) <= self::DIRECTION_TOLERANCE) {
                    $groups[$index][] = $orientation->project($element);
                    continue 2;
                }
            }
            $orientation = new self($x, $y, $handedness);
            $orientations[] = $orientation;
            $groups[] = [$orientation->project($element)];
        }
        return $groups;
    }

    private function project(PositionedTextElement $element): PositionedTextElement {
        if ($this->x === 1.0 && $this->y === 0.0 && $this->handedness === 1) {
            return $element;
        }
        $projection = new TransformationMatrix($this->x, -$this->y * $this->handedness, $this->y, $this->x * $this->handedness, 0, 0);
        $matrix = $element->getEffectiveMatrix();
        return new PositionedTextElement(
            $element->textSegments,
            $element->absoluteMatrix,
            $element->textState,
            $element->resourceContext,
            $element->actualText,
            $matrix->multiplyWith($projection),
        );
    }
}
