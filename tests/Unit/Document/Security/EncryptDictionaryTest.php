<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Document\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\EncryptDictionary;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryFactory;
use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\Name\CFMNameValue;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

#[CoversClass(EncryptDictionary::class)]
class EncryptDictionaryTest extends TestCase {
    public function testLegacyEncryptionUsesRc4(): void {
        $dictionary = new EncryptDictionary(DictionaryFactory::fromArray(null, null, ['/V' => '2']));
        static::assertSame(CFMNameValue::V2, $dictionary->getStringEncryptionMethod());
        static::assertSame(CFMNameValue::V2, $dictionary->getStreamEncryptionMethod());
    }

    public function testMissingFiltersDefaultToIdentity(): void {
        $dictionary = new EncryptDictionary(DictionaryFactory::fromArray(null, null, ['/V' => '4']));
        static::assertSame(CFMNameValue::None, $dictionary->getStringEncryptionMethod());
        static::assertSame(CFMNameValue::None, $dictionary->getStreamEncryptionMethod());
    }

    public function testNamedStreamAndStringFiltersAreResolvedIndependently(): void {
        $dictionary = new EncryptDictionary(DictionaryFactory::fromArray(null, null, [
            '/V' => '4',
            '/StmF' => '/StreamFilter',
            '/StrF' => '/StringFilter',
            '/CF' => ['/StreamFilter' => ['/CFM' => '/AESV2'], '/StringFilter' => ['/CFM' => '/V2']],
        ]));
        static::assertSame(CFMNameValue::AESV2, $dictionary->getStreamEncryptionMethod());
        static::assertSame(CFMNameValue::V2, $dictionary->getStringEncryptionMethod());
    }

    public function testExplicitIdentityDoesNotRequireNamedFilter(): void {
        $dictionary = new EncryptDictionary(DictionaryFactory::fromArray(null, null, [
            '/V' => '4', '/StmF' => '/Identity',
        ]));
        static::assertSame(CFMNameValue::None, $dictionary->getStreamEncryptionMethod());
    }

    public function testMissingNamedFilterFails(): void {
        $dictionary = new EncryptDictionary(DictionaryFactory::fromArray(null, null, ['/V' => '4', '/StmF' => '/Missing']));
        $this->expectException(ParseFailureException::class);
        $dictionary->getStreamEncryptionMethod();
    }
}
