<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Dictionary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryParser;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Integer\IntegerValue;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(DictionaryParser::class)]
class SelectedDictionaryKeysTest extends TestCase {
    public function testUnusedPrivateValuesAreNotTyped(): void {
        $stream = new InMemoryStream('<< /LC /privateName /Length 1 /Other << /Length 99 >> >>');
        $dictionary = DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes(), [DictionaryKey::LENGTH]);
        static::assertSame(1, $dictionary->getValueForKey(DictionaryKey::LENGTH, IntegerValue::class)?->value);
        static::assertCount(1, $dictionary->dictionaryEntries);
    }
}
