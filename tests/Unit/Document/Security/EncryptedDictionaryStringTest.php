<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryEntry\DictionaryEntryFactory;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\HexadecimalStringValue;
use PrinsFrank\PdfParser\Document\Security\EncryptionContext;
use PrinsFrank\PdfParser\Document\Security\FileEncryptionKey;

#[CoversClass(DictionaryEntryFactory::class)]
class EncryptedDictionaryStringTest extends TestCase {
    public function testHexadecimalStringUsesStringCryptMethod(): void {
        $key = hex2bin('000102030405060708090a0b0c0d0e0f');
        static::assertNotFalse($key);
        $context = new EncryptionContext(new FileEncryptionKey($key, CFMNameValue::None, CFMNameValue::AESV2), 42, 7);
        $entry = DictionaryEntryFactory::fromKeyValuePair(null, $context, '/Title', '<000102030405060708090a0b0c0d0e0f5ba00c6dbc21e6dc998ab998fb8f4427ea711b645a4d9b7f88b3fc1a3871b75f>');
        static::assertInstanceOf(HexadecimalStringValue::class, $entry?->value);
        static::assertSame('AES string and stream', $entry->value->getText());
    }
}
