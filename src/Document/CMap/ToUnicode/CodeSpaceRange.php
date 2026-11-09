<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CMap\ToUnicode;

readonly class CodeSpaceRange {
    public function __construct(
        public int $codeSpaceStart,
        public int $codeSpaceEnd,
        public ?int $byteSize = null,
    ) {}

    public function contains(int $characterCode, int $byteSize): bool {
        for ($shift = ($byteSize - 1) * 8; $shift >= 0; $shift -= 8) {
            $byte = ($characterCode >> $shift) & 0xff;
            $lowerBound = ($this->codeSpaceStart >> $shift) & 0xff;
            $upperBound = ($this->codeSpaceEnd >> $shift) & 0xff;
            if ($byte < $lowerBound || $byte > $upperBound) {
                return false;
            }
        }

        return true;
    }
}
