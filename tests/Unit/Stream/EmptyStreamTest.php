<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Stream\AbstractStream;
use PrinsFrank\PdfParser\Stream\FileStream;
use PrinsFrank\PdfParser\Stream\InMemoryStream;

#[CoversClass(AbstractStream::class)]
class EmptyStreamTest extends TestCase {
    public function testEmptyMemoryStreamHasEmptyStringContent(): void {
        static::assertSame('', (new InMemoryStream(''))->toString());
    }

    public function testEmptyFileStreamHasEmptyStringContent(): void {
        static::assertSame('', FileStream::fromString('')->toString());
    }
}
