<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Dictionary\Decorator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\Object\UncompressedObject\UncompressedObject;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Reference\ReferenceValue;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\Font;
use PrinsFrank\PdfParser\Document\Dictionary\Dictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryEntry\DictionaryEntry;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\ArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Array\DictionaryArrayValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Integer\IntegerValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\EncodingNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\SubtypeNameValue;

#[CoversClass(Font::class)]
class FontWidthTest extends TestCase {
    public function testWidthCalculationsRespectChangingTextStateAndDefaultWidths(): void {
        $font = new Font(new Dictionary(
            null,
            new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::TRUE_TYPE),
            new DictionaryEntry(DictionaryKey::FIRST_CHAR, new IntegerValue(32)),
            new DictionaryEntry(DictionaryKey::WIDTHS, new ArrayValue([250])),
        ));

        static::assertSame(44.0, $font->getWidthForBytes([32, 33, 32], 2, 1, 10, 2));
        static::assertSame(30.0, $font->getWidthForBytes([32, 33, 32], 0, 0, 20, 1));
        static::assertSame(0.0, $font->getWidthForBytes([], 2, 1, 10, 2));
    }

    public function testReferencedDescendantIsResolvedOnce(): void {
        $descendant = new Font(new Dictionary(null));
        $descendantObject = $this->createMock(UncompressedObject::class);
        $descendantObject->expects(static::once())->method('getDictionary')->with(Font::class)->willReturn($descendant);
        $document = $this->createMock(Document::class);
        $document->expects(static::once())->method('getObject')->with(2)->willReturn($descendantObject);
        $font = new Font(new Dictionary(
            new UncompressedObject($document, 1, 0, 0, 0),
            new DictionaryEntry(DictionaryKey::DESCENDANT_FONTS, new ReferenceValue(2, 0)),
        ));

        static::assertSame(
            [[$descendant], [$descendant]],
            [$font->getDescendantFonts(), $font->getDescendantFonts()],
        );
    }

    public function testDefaultWidthCacheIsScopedToEachFontIncludingZero(): void {
        foreach ([0, 700, 1000] as $width) {
            $font = new Font(new Dictionary(
                null,
                new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::CID_FONT_TYPE_2),
                new DictionaryEntry(DictionaryKey::DW, new IntegerValue($width)),
            ));
            static::assertSame(
                [$width / 1000.0, $width / 1000.0],
                [$font->getDefaultWidth(), $font->getDefaultWidth()],
            );
        }
    }

    public function testCompositeDefaultWidthsIgnoreWordSpacingAndRemainStable(): void {
        $font = new Font(new Dictionary(
            null,
            new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::TYPE_0),
            new DictionaryEntry(DictionaryKey::ENCODING, EncodingNameValue::IdentityH),
            new DictionaryEntry(DictionaryKey::DESCENDANT_FONTS, new DictionaryArrayValue(
                new Dictionary(
                    null,
                    new DictionaryEntry(DictionaryKey::SUBTYPE, SubtypeNameValue::CID_FONT_TYPE_2),
                    new DictionaryEntry(DictionaryKey::DW, new IntegerValue(700)),
                ),
            )),
        ));

        static::assertSame(8.0, $font->getWidthForBytes([0, 32], 100, 1, 10, 1));
        static::assertSame(15.0, $font->getWidthForBytes([0, 32], 100, 1, 20, 1));
        static::assertSame(0.7, $font->getDefaultWidth());
    }
}
