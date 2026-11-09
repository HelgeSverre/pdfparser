<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\ContentStream\PositionedText;

use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextSegment\TextSegment;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\XObject;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Rectangle\Rectangle;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

readonly class PositionedTextElement {
    private TransformationMatrix $effectiveMatrixCache;
    private float $heightCache;
    private float $advanceWidthCache;
    private float $rightCache;
    private float $layoutRightCache;
    public const WORD_BREAK_THRESHOLD_EM = 0.25;

    /** @param list<TextSegment> $textSegments */
    public function __construct(
        public array $textSegments,
        public TransformationMatrix $absoluteMatrix,
        public TextState $textState,
        public ?XObject $resourceContext = null,
        public ?string $actualText = null,
        private ?TransformationMatrix $layoutMatrix = null,
    ) {}

    /** @throws ParseFailureException */
    public function getText(Font $font): string {
        if ($this->actualText !== null) {
            return $this->actualText;
        }

        $differences = $font->getDifferences();
        $encoding = $font->getEncoding();
        $toUnicodeCMap = $font->getToUnicodeCMap();

        $text = '';
        $previousOffset = null;
        foreach ($this->textSegments as $textSegment) {
            $textSegmentText = $textSegment->getText($differences, $encoding, $toUnicodeCMap);
            if ($textSegmentText !== ''
                && $previousOffset !== null
                && $previousOffset / 1000 <= -self::WORD_BREAK_THRESHOLD_EM
                && str_ends_with($text, ' ') === false
                && str_starts_with($textSegmentText, ' ') === false) {
                $text .= ' ';
            }

            $text .= $textSegmentText;
            $previousOffset = $textSegment->offset;
        }

        return $text;
    }

    /** @return list<int> */
    public function getBytes(): array {
        $codePoints = [];
        foreach ($this->textSegments as $textSegment) {
            array_push($codePoints, ...$textSegment->textString->getBytes());
        }

        return $codePoints;
    }

    /** @internal */
    public function getLayoutMatrix(): TransformationMatrix {
        return $this->layoutMatrix ?? $this->getEffectiveMatrix();
    }

    /** @internal Font size reverses both glyph axes; horizontal scaling reverses only the baseline. */
    public function getEffectiveMatrix(): TransformationMatrix {
        if (isset($this->effectiveMatrixCache)) {
            return $this->effectiveMatrixCache;
        }
        $verticalDirection = $this->textState->getFontSize() < 0 ? -1 : 1;
        $horizontalDirection = $verticalDirection * ($this->textState->scale < 0 ? -1 : 1);
        $matrix = $this->absoluteMatrix;
        return $this->effectiveMatrixCache = $verticalDirection === 1 && $horizontalDirection === 1 ? $matrix : new TransformationMatrix(
            $matrix->scaleX * $horizontalDirection,
            $matrix->shearX * $horizontalDirection,
            $matrix->shearY * $verticalDirection,
            $matrix->scaleY * $verticalDirection,
            $matrix->offsetX,
            $matrix->offsetY,
        );
    }

    public function getHeight(): float {
        if (isset($this->heightCache)) {
            return $this->heightCache;
        }

        $matrix = $this->absoluteMatrix;
        $baselineLength = hypot($matrix->scaleX, $matrix->shearX);
        $heightScale = $baselineLength === 0.0 ? 0.0
            : ($matrix->shearX === 0.0 ? abs($matrix->scaleY) : abs($matrix->scaleX * $matrix->scaleY - $matrix->shearX * $matrix->shearY) / $baselineLength);
        return $this->heightCache = abs($this->textState->getFontSize()) * $heightScale * (abs($this->textState->scale) / 100);
    }

    /**
     * The distance along the baseline, in device space, that showing this element advances the text cursor, per the
     * displacement formula in the PDF spec §9.4.4:
     *
     *   ((w0 − Tj/1000)·Tfs + Tc + Tw·[single-byte code 32]) · Th , transformed by the text rendering matrix.
     *
     * Reconstructed here because Tj/TJ do not advance the text matrix in this parser.
     */
    public function getAdvanceWidth(Font $font): float {
        if (isset($this->advanceWidthCache)) {
            return $this->advanceWidthCache;
        }

        $scaleX = hypot($this->absoluteMatrix->scaleX, $this->absoluteMatrix->shearX);
        $fontSize = $this->textState->getFontSize();

        $glyphAdvance = $font->getWidthForBytes($this->getBytes(), $this->textState->wordSpace, $this->textState->charSpace, $fontSize, $scaleX); // Σ (w0·Tfs + Tc + Tw·[code 32]) · scaleX
        $offsetAdvance = -($this->getTotalOffset() / 1000) * $fontSize * $scaleX;                                 // − Σ(Tj)/1000 · Tfs · scaleX

        return $this->advanceWidthCache = ($glyphAdvance + $offsetAdvance) * (abs($this->textState->scale) / 100) * ($fontSize < 0 ? -1 : 1); // · Th
    }

    /** @param array<string, Font> $fontCache */
    public function getRight(Page $page, array &$fontCache): float {
        if (isset($this->rightCache)) {
            return $this->rightCache;
        }

        $font = $this->getFont($page, $fontCache);

        $matrix = $this->getEffectiveMatrix();
        if ($matrix->shearX === 0.0 && $matrix->scaleX >= 0) {
            return $this->rightCache = $this->absoluteMatrix->offsetX + $this->getAdvanceWidth($font);
        }

        $baselineLength = hypot($matrix->scaleX, $matrix->shearX);
        $advanceX = $baselineLength === 0.0 ? 0.0 : $this->getAdvanceWidth($font) * $matrix->scaleX / $baselineLength;
        return $this->rightCache = $this->absoluteMatrix->offsetX + max(0.0, $advanceX);
    }

    /**
     * @internal
     *
     * @param array<string, Font> $fontCache
     */
    public function getLayoutRight(Page $page, array &$fontCache): float {
        if (isset($this->layoutRightCache)) {
            return $this->layoutRightCache;
        }
        return $this->layoutRightCache = $this->layoutMatrix === null
            ? $this->getRight($page, $fontCache)
            : $this->layoutMatrix->offsetX + $this->getAdvanceWidth($this->getFont($page, $fontCache));
    }

    /** @param array<string, Font> $fontCache */
    public function getFont(Page $page, array &$fontCache): Font {
        $name = $this->textState->fontName ?? throw new ParseFailureException('Unable to locate font');
        $key = $this->resourceContext === null ? $name->value : spl_object_id($this->resourceContext) . '/' . $name->value;
        return $fontCache[$key] ??= $this->resourceContext?->getFontDictionary()
            ?->getObjectForReference($name)
            ?->getDictionary(Font::class)
            ?? $page->getFont($name);
    }

    /** The sum of the TJ adjustment numbers in this element's segments, in thousandths of an em. */
    public function getTotalOffset(): float {
        $totalOffset = 0.0;
        foreach ($this->textSegments as $textSegment) {
            if ($textSegment->offset !== null) {
                $totalOffset += $textSegment->offset;
            }
        }

        return $totalOffset;
    }

    public function startsWithinRectangle(Rectangle $rectangle): bool {
        return $this->absoluteMatrix->offsetX <= $rectangle->xBottomRight
            && $this->absoluteMatrix->offsetX >= $rectangle->xTopLeft
            && $this->absoluteMatrix->offsetY >= $rectangle->yTopLeft
            && $this->absoluteMatrix->offsetY <= $rectangle->yBottomRight;
    }
}
