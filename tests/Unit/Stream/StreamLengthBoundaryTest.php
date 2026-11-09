<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testDeclaredLengthPreservesBinaryEndingInCarriageReturn(): void {
        $content = 'binary' . "\r";
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Length 7 >>\nstream\n" . $content . "\r\nendstream\nendobj\n";
        $document = new Document(new InMemoryStream($pdf), Version::V1_4, new CrossReferenceSource(), null);
        $object = new UncompressedObject($document, 1, 0, 9, strlen($pdf));
        static::assertSame($content, $object->getContent()->toString());
    }
}
