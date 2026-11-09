<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CMap\ToUnicode;

use PrinsFrank\PdfParser\Exception\InvalidArgumentException;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\Exception\ParseFailureException;

/** @see 9.10.3 */
class ToUnicodeCMap {
    /** @var list<BFRange|BFChar> */
    private readonly array $bfCharRangeInfo;

    /** @var array<string, string|null> */
    private array $charCache = [];
    private readonly bool $variableWidth;

    /**
     * @no-named-arguments
     *
     * @param list<CodeSpaceRange> $codeSpaceRanges
     * @param int<1, max> $byteSize
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly array   $codeSpaceRanges,
        public readonly int     $byteSize,
        BFRange|BFChar ...$bfCharRangeInfo,
    ) {
        $this->bfCharRangeInfo = $bfCharRangeInfo;
        if ($this->byteSize < 1) {
            throw new InvalidArgumentException();
        }

        $this->variableWidth = count(array_unique(array_map(fn(CodeSpaceRange $range) => $range->byteSize ?? $this->byteSize, $codeSpaceRanges))) > 1;
    }

    /** @throws PdfParserException */
    public function textToUnicode(string $binaryString): string {
        $unicode = '';
        for ($offset = 0, $length = strlen($binaryString); $offset < $length;) {
            if (!$this->variableWidth) {
                if ($length - $offset < $this->byteSize) {
                    throw new ParseFailureException('Truncated CMap character code');
                }

                $characterCode = (int) hexdec(bin2hex(substr($binaryString, $offset, $this->byteSize)));
                $unicode .= $this->charToUnicode($characterCode) ?? '';
                $offset += $this->byteSize;
                continue;
            }

            [$characterCode, $width] = $this->readVariableWidthCharacterCode($binaryString, $offset, $length);
            $unicode .= $this->decodeCharacter($characterCode, $width) ?? '';
            $offset += $width;
        }

        return $unicode;
    }

    /** @return array{int, int} */
    private function readVariableWidthCharacterCode(string $binaryString, int $offset, int $length): array {
        $characterCode = 0;
        for ($width = 1; $width <= $this->byteSize && $offset + $width <= $length; $width++) {
            $characterCode = ($characterCode << 8) | ord($binaryString[$offset + $width - 1]);
            foreach ($this->codeSpaceRanges as $range) {
                if (($range->byteSize ?? $this->byteSize) === $width && $range->contains($characterCode, $width)) {
                    return [$characterCode, $width];
                }
            }
        }

        throw new ParseFailureException(sprintf('Character at byte offset %d does not match a CMap codespace range', $offset));
    }

    /** @throws PdfParserException */
    protected function charToUnicode(int $characterCode): ?string {
        return $this->decodeCharacter($characterCode, $this->byteSize);
    }

    private function decodeCharacter(int $characterCode, int $byteSize): ?string {
        $cacheKey = $byteSize . ':' . $characterCode;
        if (array_key_exists($cacheKey, $this->charCache)) {
            return $this->charCache[$cacheKey];
        }

        $char = null;
        foreach ($this->bfCharRangeInfo as $bfCharRangeInfo) {
            if (($bfCharRangeInfo->byteSize !== null && $bfCharRangeInfo->byteSize !== $byteSize) || !$bfCharRangeInfo->containsCharacterCode($characterCode)) {
                continue;
            }

            if (($char = $bfCharRangeInfo->toUnicode($characterCode)) !== "\0") { // Some characters map to NULL in one BFRange and to an actual character in another
                return $this->charCache[$cacheKey] = $char;
            }
        }

        if ($char === "\0") {
            return $this->charCache[$cacheKey] = $char; // Only return NULL when it is the only character this is mapped to
        }

        if ($characterCode === 0) {
            return $this->charCache[$cacheKey] = '';
        }

        return $this->charCache[$cacheKey] = null;
    }
}
