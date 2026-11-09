<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Object\CompressedObject;

use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Integer\IntegerValue;
use PrinsFrank\PdfParser\Document\Generic\Character\WhitespaceCharacter;
use PrinsFrank\PdfParser\Document\Generic\Marker;
use PrinsFrank\PdfParser\Document\Object\CompressedObject\CompressedObjectContent\CompressedObjectContentParser;
use PrinsFrank\PdfParser\Document\Security\EncryptionContext;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\Exception\RuntimeException;
use PrinsFrank\PdfParser\Stream\Stream;

/** @internal */
class CompressedObjectByteOffsetParser {
    /** @throws PdfParserException */
    public static function parse(Stream $stream, int $startOffsetObject, int $endOffsetObject, Dictionary $dictionary, ?EncryptionContext $encryptionContext = null): CompressedObjectByteOffsets {
        $startStreamPos = $stream->getStartNextLineAfter(Marker::STREAM, $startOffsetObject, $endOffsetObject)
            ?? throw new ParseFailureException(sprintf('Unable to locate marker %s', Marker::STREAM->value));
        if ($dictionary->getTypeForKey(DictionaryKey::LENGTH) === IntegerValue::class && ($lengthInteger = $dictionary->getValueForKey(DictionaryKey::LENGTH, IntegerValue::class)) !== null) {
            $length = $lengthInteger->value;
        } else {
            $endStreamPos = $stream->lastPos(Marker::END_STREAM, $stream->getSizeInBytes() - $endOffsetObject)
                ?? throw new ParseFailureException(sprintf('Unable to locate marker %s', Marker::END_STREAM->value));
            $eolPos = $stream->getEndOfCurrentLine($endStreamPos - 1, $endOffsetObject)
                ?? throw new ParseFailureException(sprintf('Unable to locate marker %s', WhitespaceCharacter::LINE_FEED->value));
            $length = $eolPos - $startStreamPos;
        }

        $content = CompressedObjectContentParser::parseBinary($encryptionContext, $stream, $startStreamPos, $length, $dictionary)->toString();
        $first = $dictionary->getValueForKey(DictionaryKey::FIRST, IntegerValue::class)
            ?? throw new RuntimeException('Expected a dictionary entry for "First", none found');
        $buffer = '';
        $previousObjectNumber = null;
        $byteOffsets = [];
        $header = substr($content, 0, $first->value);
        for ($offset = 0, $headerLength = strlen($header); $offset < $headerLength; $offset++) {
            $character = $header[$offset];
            if (WhitespaceCharacter::tryFrom($character) !== null) {
                if (trim($buffer) === '') {
                    continue;
                }

                $numberInBuffer = (int) $buffer;
                $unsignedDigits = ltrim(ltrim($buffer, '+-'), '0');
                if (preg_match('/^[+-]?[0-9]+$/D', $buffer) !== 1
                    || $numberInBuffer < 0
                    || ($unsignedDigits !== '' && $unsignedDigits !== (string) $numberInBuffer)) {

                    throw new ParseFailureException(sprintf('Number "%s" in buffer is not a valid number', $buffer));
                }

                if ($previousObjectNumber !== null) {
                    $byteOffsets[$previousObjectNumber] = $numberInBuffer;
                    $previousObjectNumber = null;
                } else {
                    $previousObjectNumber = $numberInBuffer;
                }

                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        return new CompressedObjectByteOffsets($byteOffsets);
    }
}
