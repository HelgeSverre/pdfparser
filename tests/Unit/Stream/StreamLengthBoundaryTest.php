<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Document;
use PrinsFrank\PdfParser\Document\Object\UncompressedObject\UncompressedObject;
use PrinsFrank\PdfParser\Document\Version\Version;
use PrinsFrank\PdfParser\Document\CrossReference\Source\CrossReferenceSource;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(UncompressedObject::class)]
class StreamLengthBoundaryTest extends TestCase {
    public function testEmptyContentDoesNotRequireInterpretingUnusedPrivateMetadata(): void {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /LC /iSQP /Length 0 >>\nstream\n\nendstream\nendobj\n";
        $document = new Document(new InMemoryStream($pdf), Version::V1_4, new CrossReferenceSource(), null);
        $object = new UncompressedObject($document, 1, 0, 9, strlen($pdf));
        static::assertSame('', $object->getContent()->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function binaryLeadingCarriageReturns(): iterable {
        yield 'carriage return' => ["\r"];
        yield 'carriage return followed by line feed' => ["\r\n"];
    }

    #[DataProvider('binaryLeadingCarriageReturns')]
    public function testLeadingCarriageReturnIsStreamData(string $content): void {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";
        $document = new Document(new InMemoryStream($pdf), Version::V1_4, new CrossReferenceSource(), null);
        $object = new UncompressedObject($document, 1, 0, 9, strlen($pdf));
        static::assertSame($content, $object->getContent()->toString());
    }

    /** @return iterable<string, array{string}> */
    public static function streamsWithoutAnExtraSeparator(): iterable {
        yield 'line feed data' => ["\n"];
        yield 'carriage return data' => ["\r"];
    }

    #[DataProvider('streamsWithoutAnExtraSeparator')]
    public function testEndOfLineDataIsNotAnEmptyStream(string $content): void {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Length 1 >>\nstream\n" . $content . "endstream\nendobj\n";
        $document = new Document(new InMemoryStream($pdf), Version::V1_4, new CrossReferenceSource(), null);
        $object = new UncompressedObject($document, 1, 0, 9, strlen($pdf));
        static::assertSame($content, $object->getContent()->toString());
    }

    public function testDeclaredLengthPreservesBinaryEndingInCarriageReturn(): void {

        $content = 'binary' . "\r";
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Length 7 >>\nstream\n" . $content . "\r\nendstream\nendobj\n";
        $document = new Document(new InMemoryStream($pdf), Version::V1_4, new CrossReferenceSource(), null);
        $object = new UncompressedObject($document, 1, 0, 9, strlen($pdf));
        static::assertSame($content, $object->getContent()->toString());
    }
}
