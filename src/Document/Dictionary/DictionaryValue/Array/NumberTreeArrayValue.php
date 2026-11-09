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
    private const ENTRY_PATTERN = <<<'REGEX'
        /\G
        [\x00\t\n\f\r ]*
        (?<key>[+-]?[0-9]+)(?=[\x00\t\n\f\r ]|<<)
        [\x00\t\n\f\r ]*
        (?<value>
            (?<dictionary><<(?:[^<>]|<(?!<)|>(?!>)|(?&dictionary))*>>)
            |
            [0-9]+[\x00\t\n\f\r ]+[0-9]+[\x00\t\n\f\r ]+R(?=[\x00\t\n\f\r ]|$)
        )
        [\x00\t\n\f\r ]*
        /x
        REGEX;

    /** @param array<int, Dictionary|ReferenceValue> $numberTree */
    public function __construct(
        public array $numberTree,
    ) {}

    #[Override]
    public static function fromValue(string $valueString, UncompressedObject|CompressedObject|null $object = null): ?self {
        $sanitizedValueString = trim($valueString, "\0\t\n\f\r ");
        if (!str_starts_with($sanitizedValueString, '[') || !str_ends_with($sanitizedValueString, ']')) {
            return null;
        }

        $sanitizedValueString = trim(substr(preg_replace('/%[^\n]*/', '', $sanitizedValueString) ?? throw new ParseFailureException(), 1, -1), "\0\t\n\f\r ");
        $numberTree = [];
        $offset = 0;
        while ($offset < strlen($sanitizedValueString)) {
            if (preg_match(self::ENTRY_PATTERN, $sanitizedValueString, $match, 0, $offset) !== 1) {
                return null;
            }

            $value = $match['value'];
            $numberTree[(int) $match['key']] = str_starts_with($value, '<<')
                ? DictionaryParser::parse($object, null, $dictionaryStream = new InMemoryStream($value), 0, $dictionaryStream->getSizeInBytes())
                : ReferenceValue::fromValue(str_replace(["\0", "\t", "\n", "\f", "\r"], ' ', $value)) ?? throw new ParseFailureException();
            $offset += strlen($match[0]);
        }

        return new self($numberTree);
    }
}
