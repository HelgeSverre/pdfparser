<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Extraction\SpaceDetection;

use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;

class SpaceDetector {
    public static function shouldContainExtraSpace(
        PositionedTextElement $positionedTextElement,
        PositionedTextElement $previousTextElementOnLine,
        Font $previousFontOnLine,
    ): bool {
        // Invisible (3) and clipping-only (7) text can overlay painted labels, for example CAD metadata.
        // Keep both layers, but do not concatenate their words when their positions overlap.
        if (in_array($positionedTextElement->textState->render, [3, 7], true)
            !== in_array($previousTextElementOnLine->textState->render, [3, 7], true)) {
            return true;
        }

        $gap = $positionedTextElement->getLayoutMatrix()->offsetX
            - $previousTextElementOnLine->getLayoutMatrix()->offsetX
            - $previousTextElementOnLine->getAdvanceWidth($previousFontOnLine);

        $wordBreakThreshold = abs($previousTextElementOnLine->textState->getFontSize())
            * $previousTextElementOnLine->getLayoutMatrix()->scaleX
            * (abs($previousTextElementOnLine->textState->scale) / 100)
            * PositionedTextElement::WORD_BREAK_THRESHOLD_EM;

        return $gap >= $wordBreakThreshold;
    }
}
