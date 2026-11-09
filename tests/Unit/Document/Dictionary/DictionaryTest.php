<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Dictionary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryEntry\DictionaryEntry;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\SubtypeNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\TypeNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;

#[CoversClass(Dictionary::class)]
class DictionaryTest extends TestCase {
    public function testResourceNamesCanBeLookedUpThroughEitherKeyRepresentation(): void {
        foreach ([true, false] as $useStandardKeys) {
            foreach (['Form', 'Font', 'F1'] as $name) {
                $entry = new DictionaryEntry(
                    ($useStandardKeys ? DictionaryKey::tryFrom($name) : null) ?? new NameObjectStringValue($name),
                    new ReferenceValue(5, 0),
                );
                $dictionary = new Dictionary(null, $entry);
                $nameKey = new NameObjectStringValue($name);
                static::assertSame(ReferenceValue::class, $dictionary->getTypeForKey($nameKey));
                static::assertSame(5, $dictionary->getValueForKey($nameKey, ReferenceValue::class)?->objectNumber);
                if (($enumKey = DictionaryKey::tryFrom($name)) !== null) {
                    static::assertSame(ReferenceValue::class, $dictionary->getTypeForKey($enumKey));
                    static::assertSame(5, $dictionary->getValueForKey($enumKey, ReferenceValue::class)?->objectNumber);
                }
            }
        }
    }

    public function testNameKeysMatchByValueForBothTypeAndValueLookup(): void {
        $value = new LiteralStringValue('replacement text');
        $dictionary = new Dictionary(null, new DictionaryEntry(new NameObjectStringValue('ActualText'), $value));
        $lookupKey = new NameObjectStringValue('ActualText');

        static::assertSame(LiteralStringValue::class, $dictionary->getTypeForKey($lookupKey));
        static::assertSame($value, $dictionary->getValueForKey($lookupKey, LiteralStringValue::class));
        static::assertSame($value, $dictionary->getStringValue($lookupKey));
        static::assertNull($dictionary->getTypeForKey(new NameObjectStringValue('Missing')));
        static::assertNull($dictionary->getValueForKey(new NameObjectStringValue('Missing'), LiteralStringValue::class));
    }

    public function testFirstDuplicateEntryWinsAndNameKeysRemainDistinctFromEnumKeys(): void {
        $name = new NameObjectStringValue(DictionaryKey::TYPE->value);
        $firstNameValue = new LiteralStringValue('first');
        $dictionary = new Dictionary(
            null,
            new DictionaryEntry($name, $firstNameValue),
            new DictionaryEntry(DictionaryKey::TYPE, TypeNameValue::FILE_SPEC),
            new DictionaryEntry(new NameObjectStringValue($name->value), new NameObjectStringValue('second')),
            new DictionaryEntry(DictionaryKey::TYPE, new LiteralStringValue('wrong type')),
        );

        static::assertSame(TypeNameValue::FILE_SPEC, $dictionary->getType());
        static::assertSame(TypeNameValue::class, $dictionary->getTypeForKey(DictionaryKey::TYPE));
        static::assertSame(LiteralStringValue::class, $dictionary->getTypeForKey(new NameObjectStringValue($name->value)));
        static::assertSame($firstNameValue, $dictionary->getValueForKey(new NameObjectStringValue($name->value), LiteralStringValue::class));
        static::assertCount(4, $dictionary->dictionaryEntries);
    }

    public function testGetType(): void {
        static::assertSame(
            TypeNameValue::FILE_SPEC,
            (new Dictionary(null, new DictionaryEntry(DictionaryKey::TYPE, TypeNameValue::FILE_SPEC)))
                ->getType(),
        );
    }

    public function testGetTypeReturnsTypeFromSubDictionary(): void {
        static::assertSame(
            TypeNameValue::FILE_SPEC,
            (new Dictionary(null, new DictionaryEntry(DictionaryKey::TYPE, new Dictionary(null, new DictionaryEntry(DictionaryKey::TYPE, TypeNameValue::FILE_SPEC)))))
                ->getType(),
        );
    }

    public function testGetSubType(): void {
        static::assertSame(
            SubtypeNameValue::FORM,
            (new Dictionary(null, new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::FORM)))
                ->getSubType(),
        );
    }

    public function testGetSubTypeWithNameObjectStringValue(): void {
        static::assertEquals(
            new NameObjectStringValue('/text/xml'),
            (new Dictionary(null, new DictionaryEntry(DictionaryKey::SUBTYPE, new NameObjectStringValue('/text/xml'))))
                ->getSubType(),
        );
    }

    public function testGetSubTypeReturnsSubTypeFromSubDictionary(): void {
        static::assertSame(
            SubtypeNameValue::FORM,
            (new Dictionary(null, new DictionaryEntry(DictionaryKey::SUBTYPE, new Dictionary(null, new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::FORM)))))
                ->getSubType(),
        );
    }
}
