<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Document\Dictionary\Decorator\XObject;
use PrinsFrank\PdfParser\Document\Image\ImageType;
use PrinsFrank\PdfParser\Document\Image\RasterizedImage;
use PrinsFrank\PdfParser\PdfParser;

#[CoversClass(XObject::class)]
#[CoversClass(RasterizedImage::class)]
class LzwImageExportTest extends TestCase {
    /** @return iterable<string, array{int, int, string, string, list<int>}> */
    public static function images(): iterable {
        yield '8-bit gray' => [2, 1, '/BitsPerComponent 8 /ColorSpace /DeviceGray', 'ff00', [0xffffff, 0]];
        yield 'RGB predictor' => [2, 1, '/BitsPerComponent 8 /ColorSpace /DeviceRGB /DecodeParms << /Predictor 15 /Columns 2 /Colors 3 /BitsPerComponent 8 >>', '010a141e050505', [0x0a141e, 0x0f1923]];
        yield 'CMYK' => [1, 1, '/BitsPerComponent 8 /ColorSpace /DeviceCMYK', '00ffff00', [0xff0000]];
        yield '1-bit row padding' => [3, 2, '/BitsPerComponent 1 /ColorSpace /DeviceGray', 'a040', [0xffffff, 0, 0xffffff, 0, 0xffffff, 0]];
        yield '2-bit gray' => [4, 1, '/BitsPerComponent 2 /ColorSpace /DeviceGray', '1b', [0, 0x555555, 0xaaaaaa, 0xffffff]];
        yield '4-bit gray' => [2, 1, '/BitsPerComponent 4 /ColorSpace /DeviceGray', 'a5', [0xaaaaaa, 0x555555]];
        yield '16-bit RGB' => [1, 1, '/BitsPerComponent 16 /ColorSpace /DeviceRGB', 'ffff00008000', [0xff0080]];
        yield 'indexed RGB' => [2, 1, '/BitsPerComponent 1 /ColorSpace [/Indexed /DeviceRGB 1 <ff000000ff00>]', '40', [0xff0000, 0x00ff00]];
        yield 'indexed sample above hival' => [1, 1, '/BitsPerComponent 8 /ColorSpace [/Indexed /DeviceRGB 1 <000000ffffff>]', 'ff', [0xffffff]];
        yield '8-bit indexed RGB' => [3, 1, '/BitsPerComponent 8 /ColorSpace [/Indexed /DeviceRGB 2 <fffffffefefe000000>]', '020100', [0, 0xfefefe, 0xffffff]];
    }

    /** @param list<int> $expectedPixels */
    #[DataProvider('images')]
    public function testDecodedImagesExportRealPngPixels(int $width, int $height, string $dictionary, string $hexBytes, array $expectedPixels): void {
        $bytes = hex2bin($hexBytes);
        static::assertNotFalse($bytes);
        $bits = implode('', array_map(static fn(int $code): string => str_pad(decbin($code), 9, '0', STR_PAD_LEFT), [256, ...array_map(ord(...), str_split($bytes)), 257]));
        $encoded = '';
        foreach (str_split(str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0'), 8) as $byte) {
            $encoded .= chr(intval($byte, 2));
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << /XObject << /Im 4 0 R >> >> >>',
            '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /Filter /LZWDecode ' . $dictionary . ' /Length ' . strlen($encoded) . " >>\nstream\n" . $encoded . "\nendstream",
        ];
        $pdf = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        $images = (new PdfParser())->parseString($pdf)->getImages();
        static::assertCount(1, $images);
        static::assertSame(ImageType::PNG, $images[0]->getImageType());
        $png = $images[0]->getContent()->toString();
        static::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $image = imagecreatefromstring($png);
        static::assertNotFalse($image);
        static::assertSame($width, imagesx($image));
        static::assertSame($height, imagesy($image));
        foreach ($expectedPixels as $offset => $pixel) {
            static::assertSame($pixel, imagecolorat($image, $offset % $width, intdiv($offset, $width)));
        }
    }
}
