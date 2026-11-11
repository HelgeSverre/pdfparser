<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Dictionary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryEntry\DictionaryEntryFactory;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryFactory;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryParser;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\ArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Integer\IntegerValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\NameObjectStringValue;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(DictionaryFactory::class)]
#[CoversClass(DictionaryEntryFactory::class)]
class PrivateDictionaryContextTest extends TestCase {
    public function testFillSignNamesAreLocalToItsPrivateDictionary(): void {
        $stream = new InMemoryStream('<< /Size 5 /Text (standard) /ADBE_FillSign << /Text 1234567 /Size [55.044 14.641] /Nested << /Text (private) >> >> >>');
        $dictionary = DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes());
        static::assertSame(5, $dictionary->getValueForKey(DictionaryKey::SIZE, IntegerValue::class)?->value);
        static::assertSame('standard', $dictionary->getValueForKey(DictionaryKey::TEXT, LiteralStringValue::class)?->value);
        $private = $dictionary->getValueForKey(new NameObjectStringValue('ADBE_FillSign'), Dictionary::class);
        static::assertNotNull($private);
        static::assertSame(1234567, $private->getValueForKey(new NameObjectStringValue('Text'), IntegerValue::class)?->value);
        static::assertSame(['55.044', '14.641'], $private->getValueForKey(new NameObjectStringValue('Size'), ArrayValue::class)?->value);
        $nested = $private->getValueForKey(new NameObjectStringValue('Nested'), Dictionary::class);
        static::assertSame('private', $nested?->getValueForKey(new NameObjectStringValue('Text'), LiteralStringValue::class)?->value);
    }

    public function testOtherDictionariesRetainTheirStandardValueTypes(): void {
        $this->expectException(ParseFailureException::class);
        $stream = new InMemoryStream('<< /Other << /Text 1234567 >> >>');
        DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes());
    }
}
