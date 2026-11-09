<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryFactory;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryParser;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use PrinsFrank\PdfParser\Document\Encryption\RC4;
use PrinsFrank\PdfParser\Document\Object\CompressedObject\CompressedObjectContent\CompressedObjectContentParser;
use PrinsFrank\PdfParser\Document\Security\EncryptionContext;
use PrinsFrank\PdfParser\Document\Security\FileEncryptionKey;
use PrinsFrank\PdfParser\Document\Security\StandardSecurity;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\PdfParser;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(CompressedObjectContentParser::class)]
class StreamCryptFilterTest extends TestCase {
    private const KEY = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";
    private const CIPHERTEXT = self::KEY . "\x5b\xa0\x0c\x6d\xbc\x21\xe6\xdc\x99\x8a\xb9\x98\xfb\x8f\x44\x27\xea\x71\x1b\x64\x5a\x4d\x9b\x7f\x88\xb3\xfc\x1a\x38\x71\xb7\x5f";

    public function testIdentityOverridesDefaultAes(): void {
        $dictionary = DictionaryFactory::fromArray(null, null, ['/Filter' => '/Crypt', '/DecodeParms' => ['/Type' => '/CryptFilterDecodeParms', '/Name' => '/Identity']]);
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2), 42, 7);
        static::assertSame('BT ET', CompressedObjectContentParser::parseBinary($context, new InMemoryStream('BT ET'), 0, 5, $dictionary)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function parameterArrays(): iterable {
        yield 'explicit identity' => ['[<< /Name /Identity >> << /Predictor 15 /Columns 6 /Colors 1 >>]'];
        yield 'implicit identity preserves null slot' => ['[null << /Predictor 15 /Columns 6 /Colors 1 >>]'];
    }

    #[DataProvider('parameterArrays')]
    public function testCryptThenFlateUsesEachFiltersParameters(string $parameters): void {
        $source = new InMemoryStream('<< /Filter [/Crypt /FlateDecode] /DecodeParms ' . $parameters . ' >>');
        $dictionary = DictionaryParser::parse(null, null, $source, 0, $source->getSizeInBytes());
        $compressed = gzcompress("\x01\x0a\x0a\x0a\xf1\x0a\x0a");
        static::assertNotFalse($compressed);
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2), 42, 7);
        static::assertSame('0a141e0f1923', bin2hex(CompressedObjectContentParser::parseBinary($context, new InMemoryStream($compressed), 0, strlen($compressed), $dictionary)->toString()));
    }

    public function testNamedAesFilterOverridesIdentityDefault(): void {
        $document = (new PdfParser())->parseFile(dirname(__DIR__, 3) . '/Feature/samples/encryption/aes-cleartext-metadata.pdf', security: new StandardSecurity('user-password'));
        $dictionary = DictionaryFactory::fromArray($document->getObject(1), null, ['/Filter' => '/Crypt', '/DecodeParms' => ['/Name' => '/StdCF']]);
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::None), 42, 7);
        static::assertSame('AES string and stream', CompressedObjectContentParser::parseBinary($context, new InMemoryStream(self::CIPHERTEXT), 0, strlen(self::CIPHERTEXT), $dictionary)->toString());
    }

    public function testNamedRc4FilterOverridesAesDefault(): void {
        $pdf = file_get_contents(dirname(__DIR__, 3) . '/Feature/samples/encryption/aes-cleartext-metadata.pdf');
        static::assertNotFalse($pdf);
        $document = (new PdfParser())->parseString(str_replace('/CFM /AESV2', '/CFM /V2   ', $pdf), security: new StandardSecurity('user-password'));
        $dictionary = DictionaryFactory::fromArray($document->getObject(1), null, ['/Filter' => '/Crypt', '/DecodeParms' => ['/Name' => '/StdCF']]);
        $context = new EncryptionContext(new FileEncryptionKey(self::KEY, CFMNameValue::AESV2), 42, 7);
        $ciphertext = RC4::crypt($context->getObjectEncryptionKey(), 'RC4 stream');
        static::assertSame('RC4 stream', CompressedObjectContentParser::parseBinary($context, new InMemoryStream($ciphertext), 0, strlen($ciphertext), $dictionary)->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDictionaries(): iterable {
        yield 'crypt after decoder' => ['<< /Filter [/FlateDecode /Crypt] >>'];
        yield 'repeated crypt' => ['<< /Filter [/Crypt /Crypt] >>'];
        yield 'xref crypt' => ['<< /Type /XRef /Filter /Crypt >>'];
        yield 'unknown crypt name' => ['<< /Filter /Crypt /DecodeParms << /Name /Missing >> >>'];
    }

    #[DataProvider('invalidDictionaries')]
    public function testInvalidCryptFiltersFail(string $source): void {
        $stream = new InMemoryStream($source);
        $dictionary = DictionaryParser::parse(null, null, $stream, 0, strlen($source));
        $this->expectException(ParseFailureException::class);
        CompressedObjectContentParser::parseBinary(null, new InMemoryStream('bytes'), 0, 5, $dictionary);
    }
}
