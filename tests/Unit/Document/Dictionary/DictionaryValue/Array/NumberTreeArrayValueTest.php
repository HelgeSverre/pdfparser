<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Dictionary\DictionaryValue\Array;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryEntry\DictionaryEntry;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryParser;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\NumberTreeArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Integer\IntegerValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\NumberingStyleNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\LiteralStringValue;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(NumberTreeArrayValue::class)]
class NumberTreeArrayValueTest extends TestCase {
    /** @return iterable<string, array{string}> */
    public static function pdfWhitespace(): iterable {
        yield 'null' => ["\0"];
        yield 'tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'form feed' => ["\f"];
        yield 'carriage return' => ["\r"];
        yield 'space' => [' '];
    }

    #[DataProvider('pdfWhitespace')]
    public function testPdfWhitespaceSeparatesIndirectValues(string $whitespace): void {
        $array = $whitespace . '[' . $whitespace . implode($whitespace, ['0', '1', '0', 'R', '18', '2', '0', 'R']) . $whitespace . ']' . $whitespace;
        $expected = new NumberTreeArrayValue([0 => new ReferenceValue(1, 0), 18 => new ReferenceValue(2, 0)]);
        static::assertEquals($expected, NumberTreeArrayValue::fromValue($array));
    }

    #[DataProvider('pdfWhitespace')]
    public function testPdfWhitespaceThroughDictionaryParser(string $whitespace): void {
        $stream = new InMemoryStream('<< /Nums [' . implode($whitespace, ['0', '1', '0', 'R', '18', '2', '0', 'R']) . '] >>');
        $dictionary = DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes());
        static::assertEquals(
            new NumberTreeArrayValue([0 => new ReferenceValue(1, 0), 18 => new ReferenceValue(2, 0)]),
            $dictionary->getValueForKey(DictionaryKey::NUMS, NumberTreeArrayValue::class),
        );
    }

    #[DataProvider('pdfWhitespace')]
    public function testPdfWhitespaceSeparatesDirectValues(string $whitespace): void {
        static::assertEquals(
            new NumberTreeArrayValue([0 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic))]),
            NumberTreeArrayValue::fromValue('[' . $whitespace . '0' . $whitespace . '<</S /D>>' . $whitespace . ']'),
        );
    }

    public function testPositiveSignedKeys(): void {
        static::assertEquals(new NumberTreeArrayValue([17 => new ReferenceValue(1, 0)]), NumberTreeArrayValue::fromValue('[+17 1 0 R]'));
    }

    public function testPositiveSignedKeysThroughDictionaryParser(): void {
        $stream = new InMemoryStream('<< /Nums [+17 1 0 R] >>');
        $dictionary = DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes());
        static::assertEquals(new NumberTreeArrayValue([17 => new ReferenceValue(1, 0)]), $dictionary->getValueForKey(DictionaryKey::NUMS, NumberTreeArrayValue::class));
    }

    /** @return iterable<array{string}> */
    public static function verticalTabSeparators(): iterable {
        yield ["\v[0 1 0 R]"];
        yield ["[0 1 0 R]\v"];
        yield ["[\v0 1 0 R]"];
        yield ["[0\v1 0 R]"];
        yield ["[0 1\v0 R]"];
        yield ["[0 1 0\vR]"];
        yield ["[0 1 0 R\v]"];
    }

    #[DataProvider('verticalTabSeparators')]
    public function testVerticalTabIsNotPdfWhitespace(string $array): void {
        static::assertNull(NumberTreeArrayValue::fromValue($array));
    }

    public function testVerticalTabIsRejectedThroughDictionaryParser(): void {
        $stream = new InMemoryStream("<< /Nums [0\v1 0 R] >>");
        $this->expectException(ParseFailureException::class);
        DictionaryParser::parse(null, null, $stream, 0, $stream->getSizeInBytes());
    }

    public function testWhitespaceInsideDictionaryStringsIsPreserved(): void {
        static::assertEquals(
            new NumberTreeArrayValue([0 => new Dictionary(null, new DictionaryEntry(DictionaryKey::P, new LiteralStringValue("a\0b\fc\vd")))]),
            NumberTreeArrayValue::fromValue("[0 <</P (a\0b\fc\vd)>>]"),
        );
    }

    public function testIndirectReferences(): void {
        static::assertEquals(
            new NumberTreeArrayValue([0 => new ReferenceValue(1519, 0), 18 => new ReferenceValue(1522, 0)]),
            NumberTreeArrayValue::fromValue('[0 1519 0 R 18 1522 0 R]'),
        );
    }

    public function testDoesNotAcceptPartialNumberTrees(): void {
        static::assertNull(NumberTreeArrayValue::fromValue('[0 <</S /r>> garbage]'));
        static::assertNull(NumberTreeArrayValue::fromValue('[garbage 0 <</S /r>>]'));
        static::assertNull(NumberTreeArrayValue::fromValue('[0 1519 0 R 18]'));
    }

    public function testMixedDirectAndIndirectValues(): void {
        static::assertEquals(
            new NumberTreeArrayValue([
                0 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::LowerCaseRomanNumerals)),
                18 => new ReferenceValue(1522, 0),
            ]),
            NumberTreeArrayValue::fromValue('[0 <</S /r>> 18 1522 0 R]'),
        );
    }

    public function testReferenceRequiresSeparateKeyAndObjectNumber(): void {
        static::assertNull(NumberTreeArrayValue::fromValue('[01519 0 R]'));
    }

    public function testReferenceRequiresSeparateOperatorAndNextKey(): void {
        static::assertNull(NumberTreeArrayValue::fromValue('[0 1 0 R18 2 0 R]'));
    }

    public function testFromValueWithExampleFromTable161(): void {
        static::assertEquals(
            new NumberTreeArrayValue(
                [
                    0 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::LowerCaseRomanNumerals)),
                    4 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic)),
                    7 => new Dictionary(
                        null,
                        new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic),
                        new DictionaryEntry(DictionaryKey::P, new LiteralStringValue(' A- ')),
                        new DictionaryEntry(DictionaryKey::ST, new IntegerValue(8)),
                    ),
                ],
            ),
            NumberTreeArrayValue::fromValue(
                <<<TREE
                [ 0 <</S /r>> %A number tree containing
                4 <</S /D>> %three page label dictionaries
                7 <</S /D
                /P ( A- )
                /St 8
                >>
                ]
                TREE,
            ),
        );
        static::assertEquals(
            new NumberTreeArrayValue(
                [
                    0 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::LowerCaseRomanNumerals)),
                    4 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic)),
                    7 => new Dictionary(
                        null,
                        new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic),
                        new DictionaryEntry(DictionaryKey::P, new LiteralStringValue(' A- ')),
                        new DictionaryEntry(DictionaryKey::ST, new IntegerValue(8)),
                    ),
                ],
            ),
            NumberTreeArrayValue::fromValue('[ 0 <</S /r>>4 <</S /D>> 7 <</S /D/P ( A- )/St 8>>]'),
        );
        static::assertEquals(
            new NumberTreeArrayValue(
                [
                    0 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::LowerCaseRomanNumerals)),
                    4 => new Dictionary(null, new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic)),
                    7 => new Dictionary(
                        null,
                        new DictionaryEntry(DictionaryKey::S, NumberingStyleNameValue::DecimalArabic),
                        new DictionaryEntry(DictionaryKey::P, new LiteralStringValue(' A- ')),
                        new DictionaryEntry(DictionaryKey::ST, new IntegerValue(8)),
                    ),
                ],
            ),
            NumberTreeArrayValue::fromValue('[0<</S/r>>4<</S/D>>7<</S/D/P( A- )/St 8>>]'),
        );
    }
}
