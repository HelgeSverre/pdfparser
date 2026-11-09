<?php
declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Object\CompressedObject\CompressedObjectContent;

use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\ArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\DictionaryArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\FilterNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\TypeNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\Security\EncryptionContext;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\Exception\RuntimeException;
use PrinsFrank\PdfParser\Stream\InMemoryStream;
use PrinsFrank\PdfParser\Stream\Stream;

/** @internal */
class CompressedObjectContentParser {
    /**
     * @param Stream|Document $context the document (or stream during parsing) that is currently being parsed
     * @throws PdfParserException
     * @return Stream with content in binary format
     */
    public static function parseBinary(?EncryptionContext $encryptionContext, Stream|Document $context, int $startPos, int $nrOfBytes, Dictionary $dictionary): Stream {
        $content = ($context instanceof Document ? $context->stream : $context)->read($startPos, $nrOfBytes);
        $filters = self::getFilters($context, $dictionary);
        $type = $encryptionContext !== null || in_array(FilterNameValue::CRYPT, $filters, true)
            ? $dictionary->getValueForKey(DictionaryKey::TYPE, TypeNameValue::class)
            : null;
        foreach ($filters as $index => $filter) {
            if ($filter === FilterNameValue::CRYPT && ($index !== 0 || $type === TypeNameValue::X_REF)) {
                throw new ParseFailureException('Crypt must be the first filter and cannot be used on cross-reference streams');
            }
        }
        if (($filters[0] ?? null) === FilterNameValue::CRYPT) {
            $params = $dictionary->getTypeForKey(DictionaryKey::DECODE_PARMS) === DictionaryArrayValue::class
                ? ($dictionary->getValueForKey(DictionaryKey::DECODE_PARMS, DictionaryArrayValue::class)?->dictionaries[0] ?? null)
                : $dictionary->getSubDictionary(DictionaryKey::DECODE_PARMS);
            $name = $params?->getValueForKey(DictionaryKey::NAME, NameObjectStringValue::class);
            if ($name !== null && $name->getText() !== 'Identity') {
                $encryptDictionary = $dictionary->object?->document->getEncryptDictionary()
                    ?? ($context instanceof Document ? $context->getEncryptDictionary() : null)
                    ?? throw new ParseFailureException('Named Crypt filter requires an encryption dictionary');
                $method = $encryptDictionary->getCryptFilterMethod($name);
                if ($method !== CFMNameValue::None) {
                    $content = ($encryptionContext ?? throw new ParseFailureException('Named Crypt filter requires an encryption context'))
                        ->decryptStream($content, $type, $method);
                }
            }
        } elseif ($encryptionContext !== null) {
            $content = $encryptionContext->decryptStream($content, $type);
        }
        foreach ($filters as $index => $filter) {
            if ($filter !== FilterNameValue::CRYPT) {
                $content = $filter->decodeBinary($content, $dictionary, $index);
            }
        }
        return new InMemoryStream($content);
    }

    /** @return list<FilterNameValue> */
    private static function getFilters(Stream|Document $context, Dictionary $dictionary): array {
        $filterType = $dictionary->getTypeForKey(DictionaryKey::FILTER);
        if ($filterType === null) {
            return [];
        }
        if ($filterType === FilterNameValue::class) {
            return [$dictionary->getValueForKey(DictionaryKey::FILTER, FilterNameValue::class) ?? throw new ParseFailureException()];
        }
        if ($filterType === ArrayValue::class) {
            $array = $dictionary->getValueForKey(DictionaryKey::FILTER, ArrayValue::class) ?? throw new ParseFailureException();
        } elseif ($filterType === ReferenceValue::class) {
            if (!$context instanceof Document) {
                throw new ParseFailureException('Filter reference is only supported in a Document');
            }
            $object = $dictionary->getObjectForReference(DictionaryKey::FILTER) ?? throw new ParseFailureException('Unable to retrieve filter object');
            $array = ArrayValue::fromValue($object->getContent()->toString());
            if (!$array instanceof ArrayValue) {
                throw new ParseFailureException('Filter object is not an array');
            }
        } else {
            throw new RuntimeException(sprintf('Expected filter to be a FilterNameValue or ArrayValue, got %s', $filterType));
        }
        $filters = [];
        foreach ($array->value as $value) {
            if (!is_string($value) || ($filter = FilterNameValue::tryFrom(ltrim($value, '/'))) === null) {
                throw new ParseFailureException('Invalid stream filter');
            }
            $filters[] = $filter;
        }
        return $filters;
    }
}
