# Extracting images

To extract all images in a document and store them on your machine, you could do something like this:

```php
/** @var \PrinsFrank\PdfParser\Document\Document $document */
foreach ($document->getImages() as $index => $image) {
    if (($imageFileExtension = $image->getImageType()?->getFileExtension()) === null) {
        continue; // You could still save the file with a default file extension like 'jpg', but it is not clear what kind of image this is.
    }

    file_put_contents(sprintf('%s/image_%d.%s', __DIR__, $index, $imageFileExtension), $image->getContent()->toString());
}
```

Images compressed with LZWDecode or FlateDecode are exported as PNG files. Their stream filters and predictors are decoded before the pixel data is converted.

Indexed images use their color lookup table when producing PNG pixels. Both a lookup stream and a hexadecimal lookup string are supported.

PNG conversion supports 1-, 2-, 4-, 8-, and 16-bit components. Packed samples are read one row at a time, including padding at the end of each row. PNG output uses 8-bit channels and the default Decode ranges for ordinary images. CMYK samples are converted to RGB without color-profile conversion.
