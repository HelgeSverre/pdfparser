<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CMap\ToUnicode;

readonly class CodeSpaceRange {
    public function __construct(
        public int $codeSpaceStart,
        public int $codeSpaceEnd,
        public ?int $byteSize = null,
    ) {}

    public function contains(int $code, int $byteSize): bool {
        for ($shift = ($byteSize - 1) * 8; $shift >= 0; $shift -= 8) {
            $byte = ($code >> $shift) & 0xff;
            if ($byte < (($this->codeSpaceStart >> $shift) & 0xff) || $byte > (($this->codeSpaceEnd >> $shift) & 0xff)) {
                return false;
            }
        }

        return true;
    }
}
