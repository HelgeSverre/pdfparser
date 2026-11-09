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
