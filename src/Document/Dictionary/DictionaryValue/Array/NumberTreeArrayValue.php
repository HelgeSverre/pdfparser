<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array;

use Override;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryParser;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\DocumentAwareDictionaryValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;
use PrinsFrank\PdfParser\Document\Object\CompressedObject\CompressedObject;
use PrinsFrank\PdfParser\Document\Object\UncompressedObject\UncompressedObject;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

readonly class NumberTreeArrayValue implements DocumentAwareDictionaryValue {
    /** @param array<int, Dictionary|ReferenceValue> $numberTree */
    public function __construct(
        public array $numberTree,
    ) {}

    #[Override]
    public static function fromValue(string $valueString, UncompressedObject|CompressedObject|null $object = null): ?self {
        $sanitizedValueString = trim($valueString);
        if (!str_starts_with($sanitizedValueString, '[') || !str_ends_with($sanitizedValueString, ']')) {
            return null;
        }

        $sanitizedValueString = trim(substr(preg_replace('/%[^\n]*/', '', $sanitizedValueString) ?? throw new ParseFailureException(), 1, -1));
        $pageLabelDictionaries = [];
        $offset = 0;
        while ($offset < strlen($sanitizedValueString)) {
            if (preg_match('/\G\s*(-?\d+)\s*(?<value>(?<dictionary><<(?:[^<>]|<(?!<)|>(?!>)|(?&dictionary))*>>)|\d+\s+\d+\s+R)\s*/', $sanitizedValueString, $match, 0, $offset) !== 1) {
                return null;
            }

            $value = $match['value'];
            $pageLabelDictionaries[(int) $match[1]] = str_starts_with($value, '<<')
                ? DictionaryParser::parse($object, null, $dictionaryStream = new InMemoryStream($value), 0, $dictionaryStream->getSizeInBytes())
                : ReferenceValue::fromValue($value) ?? throw new ParseFailureException();
            $offset += strlen($match[0]);
        }

        return new self($pageLabelDictionaries);
    }
}
