<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\Image;

use PrinsFrank\PdfParser\Document\Dictionary\DictionaryValue\TextString\HexadecimalStringValue;
use PrinsFrank\PdfParser\Document\Image\ColorSpace\ColorSpace;
use PrinsFrank\PdfParser\Document\Image\ColorSpace\Components;
use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Stream\InMemoryStream;
use PrinsFrank\PdfParser\Stream\Stream;

class RasterizedImage {
    /**
     * @internal
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @throws ParseFailureException
     */
    public static function toPNG(ColorSpace $colorSpace, int $width, int $height, int $bitsPerComponent, Stream $content, bool $imageMask = false, bool $invertMask = false): Stream {
        if (!in_array($bitsPerComponent, [1, 2, 4, 8, 16], true) || ($imageMask && $bitsPerComponent !== 1)) {
            throw new ParseFailureException(sprintf('Unsupported BitsPerComponent %d', $bitsPerComponent));
        }
        $components = $colorSpace->getComponents();
        $samplesPerPixel = $colorSpace->isIndexed ? 1 : $components->value;
        $rowBytes = (int) ceil($width * $samplesPerPixel * $bitsPerComponent / 8);
        $bytes = $content->toString();
        if (strlen($bytes) < $rowBytes * $height) {
            throw new ParseFailureException('Stream content is smaller than expected');
        }
        $lookup = $colorSpace->isIndexed
            ? ($colorSpace->LUTObj?->getContent()->toString() ?? HexadecimalStringValue::fromValue($colorSpace->fallbackLUT ?? '')?->getBinaryString())
            : null;
        if ($colorSpace->isIndexed && $lookup === null) {
            throw new ParseFailureException('Missing indexed color lookup table');
        }
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new ParseFailureException('Unable to create image');
        }
        if ($imageMask) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $maxSample = (1 << $bitsPerComponent) - 1;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $values = [];
                for ($component = 0; $component < $samplesPerPixel; $component++) {
                    $bitOffset = $y * $rowBytes * 8 + ($x * $samplesPerPixel + $component) * $bitsPerComponent;
                    $byteOffset = intdiv($bitOffset, 8);
                    $value = $bitsPerComponent === 16
                        ? (ord($bytes[$byteOffset]) << 8) | ord($bytes[$byteOffset + 1])
                        : (ord($bytes[$byteOffset]) >> (8 - $bitsPerComponent - $bitOffset % 8)) & $maxSample;
                    $values[] = $colorSpace->isIndexed ? $value : (int) round($value * 255 / $maxSample);
                }
                if ($lookup !== null) {
                    $index = $values[0];
                    $lookupOffset = $index * $components->value;
                    if ($index > $colorSpace->maxIndexLUT || $lookupOffset + $components->value > strlen($lookup)) {
                        throw new ParseFailureException('Index in LUT is too large');
                    }
                    $values = [];
                    for ($component = 0; $component < $components->value; $component++) {
                        $values[] = ord($lookup[$lookupOffset + $component]);
                    }
                }
                [$r, $g, $b] = match ($components) {
                    Components::Gray => [$values[0], $values[0], $values[0]],
                    Components::RGB => [$values[0], $values[1], $values[2]],
                    Components::CMYK => [
                        (int) (255 * (1 - $values[0] / 255) * (1 - $values[3] / 255)),
                        (int) (255 * (1 - $values[1] / 255) * (1 - $values[3] / 255)),
                        (int) (255 * (1 - $values[2] / 255) * (1 - $values[3] / 255)),
                    ],
                };
                $color = $imageMask
                    ? ((($values[0] !== 0) !== $invertMask) ? 127 << 24 : 0)
                    : ($r << 16) | ($g << 8) | $b;
                imagesetpixel($image, $x, $y, $color);
            }
        }

        ob_start();
        imagepng($image);
        $imageContent = ob_get_clean();
        if ($imageContent === false) {
            throw new ParseFailureException('Unable to decode image');
        }
        return new InMemoryStream($imageContent);
    }
}
