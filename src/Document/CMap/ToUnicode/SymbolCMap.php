<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CMap\ToUnicode;

use Override;
use PrinsFrank\Core14FontMetrics\Symbol;
use PrinsFrank\GlyphLists\AGlyphList;

/** @see ISO 32000-1, Annex D.5: Symbol Set and Encoding */
class SymbolCMap extends ToUnicodeCMap {
    public function __construct() {
        parent::__construct([new CodeSpaceRange(0, 255, 1)], 1);
    }

    #[Override]
    protected function charToUnicode(int $characterCode): string {
        $glyph = Symbol::fromCharacterCode($characterCode);
        return ($glyph === null ? null : AGlyphList::tryFrom($glyph->name)?->getChar()) ?? chr($characterCode);
    }
}
