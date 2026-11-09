<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\TypeNameValue;
use PrinsFrank\PdfParser\Document\Security\EncryptionContext;
use PrinsFrank\PdfParser\Document\Security\FileEncryptionKey;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

#[CoversClass(EncryptionContext::class)]
class EncryptionContextTest extends TestCase {
    private const KEY = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";
    private const CIPHERTEXT = self::KEY . "\x5b\xa0\x0c\x6d\xbc\x21\xe6\xdc\x99\x8a\xb9\x98\xfb\x8f\x44\x27\xea\x71\x1b\x64\x5a\x4d\x9b\x7f\x88\xb3\xfc\x1a\x38\x71\xb7\x5f";

    public function testAesObjectKeyAndCbcDecryption(): void {
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2, CFMNameValue::AESV2), 42, 7);
        static::assertSame('e58997c15874e193bb01f8823ec51314', bin2hex($context->getObjectEncryptionKey(CFMNameValue::AESV2)));
        static::assertSame('AES string and stream', $context->decryptString(self::CIPHERTEXT));
        static::assertSame('AES string and stream', $context->decryptStream(self::CIPHERTEXT));
    }

    public function testStreamAndStringFiltersAreIndependent(): void {
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2, CFMNameValue::None), 42, 7);
        static::assertSame('Identity string', $context->decryptString('Identity string'));
        static::assertSame('AES string and stream', $context->decryptStream(self::CIPHERTEXT));
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::None, CFMNameValue::AESV2), 42, 7);
        static::assertSame('Identity stream', $context->decryptStream('Identity stream'));
        static::assertSame('AES string and stream', $context->decryptString(self::CIPHERTEXT));
    }

    public function testCrossReferenceAndClearMetadataStreamsAreNotDecrypted(): void {
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2, CFMNameValue::AESV2, false), 42, 7);
        static::assertSame('Cross reference', $context->decryptStream('Cross reference', TypeNameValue::X_REF));
        static::assertSame('<metadata/>', $context->decryptStream('<metadata/>', TypeNameValue::METADATA));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCiphertexts(): iterable {
        yield 'missing IV' => [''];
        yield 'IV without ciphertext' => [self::KEY];
        yield 'partial block' => [self::KEY . 'partial'];
        $invalidPadding = self::CIPHERTEXT;
        $invalidPadding[31] = "\x26";
        yield 'incorrect PKCS7 padding' => [$invalidPadding];
    }

    #[DataProvider('invalidCiphertexts')]
    public function testMalformedAesDataIsRejected(string $ciphertext): void {
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2), 42, 7);
        $this->expectException(ParseFailureException::class);
        $context->decryptStream($ciphertext);
    }
}
