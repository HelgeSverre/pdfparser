<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryKey\DictionaryKey;
use PrinsFrank\PdfParser\Document\Security\StandardSecurity;
use PrinsFrank\PdfParser\Exception\AuthenticationFailedException;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(PdfParser::class)]
class EncryptedDocumentTest extends TestCase {
    /** @return iterable<string, array{string, string}> */
    public static function encryptedDocuments(): iterable {
        yield '40-bit default length' => ['legacy-40.pdf', '17013de290'];
        yield 'RC4 object stream' => ['rc4-object-stream.pdf', '3c5fa4b0cce43a0b6dfc7b7db4379c0f'];
        yield 'AES object stream' => ['aes-object-stream.pdf', '3c5fa4b0cce43a0b6dfc7b7db4379c0f'];
        yield 'AES clear metadata' => ['aes-cleartext-metadata.pdf', 'd22ef63010d5df3e16fc300c0af43797'];
    }

    #[DataProvider('encryptedDocuments')]
    public function testUserAndOwnerPasswordsDecryptStreamsAndStrings(string $file, string $key): void {
        foreach ([new StandardSecurity('user-password'), new StandardSecurity(ownerPassword: 'owner-password'), new StandardSecurity('user-password', 'wrong-owner')] as $security) {
            $document = (new PdfParser())->parseFile(__DIR__ . '/samples/encryption/' . $file, security: $security);
            static::assertSame($key, $document->fileEncryptionKey?->toHex());
            static::assertSame('Encrypted text', $document->getText());
            static::assertSame('Public encryption fixture', $document->getInformationDictionary()?->getTitle());
            static::assertSame('Literal (parentheses) and \\path', $document->getInformationDictionary()->getSubject());
            static::assertSame(
                '<?xml version="1.0"?><metadata>Visible metadata</metadata>',
                $document->getCatalog()->getObjectForReference(DictionaryKey::METADATA)?->getContent()->toString(),
            );
        }
    }

    #[DataProvider('encryptedDocuments')]
    public function testWrongPasswordsAreRejected(string $file, string $key): void {
        $this->expectException(AuthenticationFailedException::class);
        (new PdfParser())->parseFile(__DIR__ . '/samples/encryption/' . $file, security: new StandardSecurity('wrong-user', 'wrong-owner'));
    }

    #[DataProvider('encryptedDocuments')]
    public function testRequiredPasswordsCannotBeOmitted(string $file, string $key): void {
        $this->expectException(AuthenticationFailedException::class);
        (new PdfParser())->parseFile(__DIR__ . '/samples/encryption/' . $file);
    }

    public function testVersionFourUses128BitsWithoutAnEncryptionLength(): void {
        $pdf = file_get_contents(__DIR__ . '/samples/encryption/aes-cleartext-metadata.pdf');
        static::assertNotFalse($pdf);
        $pdf = str_replace('/Length 128', str_repeat(' ', strlen('/Length 128')), $pdf, $replacements);
        static::assertSame(1, $replacements);
        foreach ([new StandardSecurity('user-password'), new StandardSecurity(ownerPassword: 'owner-password')] as $security) {
            $document = (new PdfParser())->parseString($pdf, security: $security);
            static::assertSame('d22ef63010d5df3e16fc300c0af43797', $document->fileEncryptionKey?->toHex());
            static::assertSame('Encrypted text', $document->getText());
        }
    }

    public function testLegacyEmptyPasswordAndOmittedLength(): void {

        $document = (new PdfParser())->parseFile(__DIR__ . '/samples/encryption/legacy-40-empty-password.pdf');
        static::assertNull($document->getEncryptDictionary()?->getLengthFileEncryptionKeyInBits());
        static::assertSame('75593d17a0', $document->fileEncryptionKey?->toHex());
        static::assertSame('Encrypted text', $document->getText());
    }
}
