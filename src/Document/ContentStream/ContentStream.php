<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\ContentStream;

use PrinsFrank\PdfParser\Document\ContentStream\Command\ContentStreamCommand;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\Object\MarkedContentOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\Object\InlineImageOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\PathPaintingOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\GraphicsStateOperator;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\IncludesXObjects;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithColorState;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithTextState;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\InteractsWithTransformationMatrix;
use PrinsFrank\PdfParser\Document\ContentStream\Command\Operator\State\Interaction\ProducesPositionedTextElements;
use PrinsFrank\PdfParser\Document\ContentStream\Object\TextObject;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\ColorState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\PositionedTextElement;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TextState;
use PrinsFrank\PdfParser\Document\ContentStream\PositionedText\TransformationMatrix;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Page;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\XObject;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryParser;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\Stream\InMemoryStream;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

/** @api */
readonly class ContentStream {
    /** @var list<TextObject|ContentStreamCommand> */
    public array $content;

    /** @no-named-arguments */
    public function __construct(
        TextObject|ContentStreamCommand... $content,
    ) {
        $this->content = $content;
    }

    /**
     * @param list<int> $visitedObjectIds
     * @return list<PositionedTextElement>
     */
    public function getPositionedTextElements(Page|XObject $context, TransformationMatrix $transformationMatrix, array $visitedObjectIds): array {
        $positionedTextElements = $transformationStateStack = $textStateStack = $markedContentStack = [];
        $textState = new TextState(null, null); // See table 103, Tf operator for initial value
        $colorState = new ColorState();
        $previousReplacement = null;
        foreach ($this->content as $content) {
            if ($content instanceof ContentStreamCommand) {
                if ($content->operator instanceof PathPaintingOperator || $content->operator instanceof IncludesXObjects || $content->operator instanceof InlineImageOperator) {
                    $previousReplacement = null;
                }
                if ($content->operator instanceof MarkedContentOperator) {
                    $this->applyMarkedContent($content, $context, $markedContentStack, $positionedTextElements, $previousReplacement);
                }
                if ($content->operator === GraphicsStateOperator::SaveCurrentStateToStack) {
                    $transformationStateStack[] = $transformationMatrix;
                    $textStateStack[] = $textState;
                } elseif ($content->operator === GraphicsStateOperator::RestoreMostRecentStateFromStack) {
                    $transformationMatrix = array_pop($transformationStateStack)
                        ?? throw new ParseFailureException();
                    $textState = array_pop($textStateStack)
                        ?? throw new ParseFailureException();
                }

                if ($content->operator instanceof InteractsWithColorState) {
                    $colorState = $content->operator->applyToColorState($content->operands, $colorState);
                }

                if ($content->operator instanceof InteractsWithTextState) {
                    $textState = $content->operator->applyToTextState($content->operands, $textState);
                }

                if ($content->operator instanceof InteractsWithTransformationMatrix) {
                    $transformationMatrix = $content->operator->applyToTransformationMatrix($content->operands, $transformationMatrix, $textState);
                }

                if ($content->operator instanceof IncludesXObjects) {
                    $positionedTextElements = [...$positionedTextElements, ...$content->operator->getPositionedTextElements($content->operands, $transformationMatrix, $context, $visitedObjectIds)];
                }

                continue;
            }

            $textMatrix = new TransformationMatrix(1, 0, 0, 1, 0, 0); // Identity matrix, See Table 106, Tm operator for initial value in text object
            foreach ($content->contentStreamCommands as $contentStreamCommand) {
                if ($contentStreamCommand->operator instanceof MarkedContentOperator) {
                    $this->applyMarkedContent($contentStreamCommand, $context, $markedContentStack, $positionedTextElements, $previousReplacement);
                }
                if ($contentStreamCommand->operator instanceof InteractsWithColorState) {
                    $colorState = $contentStreamCommand->operator->applyToColorState($contentStreamCommand->operands, $colorState);
                }

                if ($contentStreamCommand->operator instanceof InteractsWithTextState) {
                    $textState = $contentStreamCommand->operator->applyToTextState($contentStreamCommand->operands, $textState);
                }

                if ($contentStreamCommand->operator instanceof InteractsWithTransformationMatrix) {
                    $textMatrix = $contentStreamCommand->operator->applyToTransformationMatrix($contentStreamCommand->operands, $textMatrix, $textState);
                }

                if ($contentStreamCommand->operator instanceof ProducesPositionedTextElements && $textState !== null) {
                    $element = $contentStreamCommand->operator->getPositionedTextElement($contentStreamCommand->operands, $textMatrix, $transformationMatrix, $textState, $colorState);
                    $positionedTextElements[] = $context instanceof XObject
                        ? new PositionedTextElement($element->textSegments, $element->absoluteMatrix, $element->textState, $context)
                        : $element;
                }
            }
        }

        return $positionedTextElements;
    }

    /**
     * @param list<array{int, ?string, ?int}> $stack
     * @param list<PositionedTextElement> $elements
     * @param ?array{int, int, int} $previousReplacement
     */
    private function applyMarkedContent(ContentStreamCommand $command, Page|XObject $context, array &$stack, array &$elements, ?array &$previousReplacement): void {
        if ($command->operator === MarkedContentOperator::BeginMarkedContent || $command->operator === MarkedContentOperator::BeginMarkedContentWithProperties) {
            $previousStart = $previousReplacement !== null && $previousReplacement[1] === count($elements) && $previousReplacement[2] === count($stack)
                ? $previousReplacement[0] : null;
            $stack[] = [count($elements), $command->operator === MarkedContentOperator::BeginMarkedContentWithProperties ? $this->getActualText($command->operands, $context) : null, $previousStart];
            $previousReplacement = null;
            return;
        }
        if ($command->operator !== MarkedContentOperator::EndMarkedContent || ($scope = array_pop($stack)) === null) {
            return;
        }
        $previousReplacement = null;
        [$start, $actualText, $previousStart] = $scope;
        if ($actualText === null) {
            return;
        }
        if (!isset($elements[$start])) {
            if ($actualText === '' && $previousStart !== null) {
                $previousReplacement = [$previousStart, count($elements), count($stack)];
            }
            return;
        }

        if ($previousStart !== null) {
            if (!isset($elements[$previousStart])) {
                throw new ParseFailureException('Missing previous ActualText anchor');
            }
            $first = $elements[$previousStart];
            $elements[$previousStart] = new PositionedTextElement($first->textSegments, $first->absoluteMatrix, $first->textState, $first->resourceContext, ($first->actualText ?? '') . $actualText);
            $actualText = '';
        }

        for ($index = $start, $count = count($elements); isset($elements[$index]) && $index < $count; $index++) {
            $element = $elements[$index];
            $elements[$index] = new PositionedTextElement($element->textSegments, $element->absoluteMatrix, $element->textState, $element->resourceContext, $index === $start ? $actualText : '');
        }
        $previousReplacement = [$previousStart ?? $start, count($elements), count($stack)];
    }

    private function getActualText(string $operands, Page|XObject $context): ?string {
        if (preg_match('~^/[^\x00\x09\x0A\x0C\x0D\x20/<>()\[\]]+[\x00\x09\x0A\x0C\x0D\x20]*(.*)$~s', $operands, $matches) !== 1) {
            return null;
        }
        $properties = trim($matches[1]);
        if (str_starts_with($properties, '<<')) {
            $stream = new InMemoryStream($properties);
            $dictionary = DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes());
        } elseif (str_starts_with($properties, '/')) {
            $dictionary = $context->getResourceDictionary()?->getSubDictionary(DictionaryKey::PROPERTIES)
                ?->getValueForKey(new NameObjectStringValue(substr($properties, 1)), Dictionary::class);
        } else {
            return null;
        }
        return $dictionary?->getStringValue(DictionaryKey::ACTUAL_TEXT)?->getText();
    }
}
