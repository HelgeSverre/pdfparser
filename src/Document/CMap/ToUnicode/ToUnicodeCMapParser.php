<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Document\CMap\ToUnicode;

use PrinsFrank\PdfParser\Exception\ParseFailureException;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\Stream\Stream;

/** @internal */
class ToUnicodeCMapParser {
    /** @throws PdfParserException */
    public static function parse(Stream $stream, int $startOffset, int $nrOfBytes): ToUnicodeCMap {
        $beginCodeSpaceRangePos = $stream->firstPos(ToUnicodeCMapOperator::BeginCodeSpaceRange, $startOffset, $startOffset + $nrOfBytes)
            ?? throw new ParseFailureException(sprintf('Missing %s', ToUnicodeCMapOperator::BeginCodeSpaceRange->value));
        $beginCodeSpaceRangePos += strlen(ToUnicodeCMapOperator::BeginCodeSpaceRange->value);
        $endCodeSpaceRangePos = $stream->firstPos(ToUnicodeCMapOperator::EndCodeSpaceRange, $beginCodeSpaceRangePos, $startOffset + $nrOfBytes)
            ?? throw new ParseFailureException();
        $codeSpaceRangeSectionString = str_replace(["\0", "\t", "\n", "\f", "\r", ' '], '', $stream->read($beginCodeSpaceRangePos, $endCodeSpaceRangePos - $beginCodeSpaceRangePos));
        $codeSpaceRanges = [];
        $byteSize = null;
        for ($offset = 0; $offset < strlen($codeSpaceRangeSectionString); $offset += strlen($matchesSpaceRange[0])) {
            if (preg_match('/\G<(?P<start>[^>]+)><(?P<end>[^>]+)>/', $codeSpaceRangeSectionString, $matchesSpaceRange, 0, $offset) !== 1) {
                throw new ParseFailureException('Unrecognized codespacerange format');
            }

            $start = self::normalizeHexadecimalString($matchesSpaceRange['start']);
            $end = self::normalizeHexadecimalString($matchesSpaceRange['end']);
            if (strlen($start) !== strlen($end)) {
                throw new ParseFailureException(sprintf('Start(%s) and end(%s) of codespacerange don\'t have the same number of bytes', $start, $end));
            }

            $byteSizeRange = intdiv(strlen($start), 2);
            if ($byteSizeRange < 1 || $byteSizeRange > 4) {
                throw new ParseFailureException('Codespaceranges must contain between one and four bytes');
            }

            $byteSize = max($byteSize ?? 1, $byteSizeRange);
            $codeSpaceRanges[] = new CodeSpaceRange((int) hexdec($start), (int) hexdec($end), $byteSizeRange);
        }

        /** @var array<int, list<BFRange|BFChar>> $bfCharRangeInfo where the first index is used to track the position of the element in the CMap */
        $bfCharRangeInfo = [];
        $lastPos = $startOffset;
        while (($beginBFCharPos = $stream->firstPos(ToUnicodeCMapOperator::BeginBFChar, $lastPos, $startOffset + $nrOfBytes)) !== null) {
            $beginBFCharPos += strlen(ToUnicodeCMapOperator::BeginBFChar->value);
            $endBFCharPos = $stream->firstPos(ToUnicodeCMapOperator::EndBFChar, $beginBFCharPos, $startOffset + $nrOfBytes)
                ?? throw new ParseFailureException();
            if (preg_match_all('/\s*<(?P<source>[^>]+)>\s*<(?P<destination>[^>]+)>\s*/', $stream->read($beginBFCharPos, $endBFCharPos - $beginBFCharPos), $matchesBFChar, PREG_SET_ORDER) === false) {
                throw new ParseFailureException('An error occurred during parsing of bfChar');
            }

            foreach ($matchesBFChar as $matchBFChar) {
                $source = self::normalizeHexadecimalString($matchBFChar['source']);
                $bfCharRangeInfo[$beginBFCharPos][] = new BFChar((int) hexdec($source), self::normalizeHexadecimalString($matchBFChar['destination']), intdiv(strlen($source), 2));
            }
            $lastPos = $endBFCharPos;
        }

        $lastPos = $startOffset;
        while (($beginBFRangePos = $stream->firstPos(ToUnicodeCMapOperator::BeginBFRange, $lastPos, $startOffset + $nrOfBytes)) !== null) {
            $endBFRangePos = $stream->firstPos(ToUnicodeCMapOperator::EndBFRange, $beginBFRangePos, $startOffset + $nrOfBytes)
                ?? throw new ParseFailureException();
            if (preg_match_all('/\s*<(?P<start>[^>]+)>\s*<(?P<end>[^>]+)>\s*(?P<targetString>(<[^>]+>)|(\[\s*(<[^>]+>\s*)+\]))/', $stream->read($beginBFRangePos, $endBFRangePos - $beginBFRangePos), $matchesBFRange, PREG_SET_ORDER) === false) {
                throw new ParseFailureException('An error occurred during parsing of bfRange');
            }

            foreach ($matchesBFRange as $matchBFRange) {
                $targetString = trim($matchBFRange['targetString']);
                if (str_starts_with($targetString, '[') && str_ends_with($targetString, ']')) {
                    preg_match_all('/<([^>]+)>/', $targetString, $hexMatches);
                    $targetValues = array_map(self::normalizeHexadecimalString(...), $hexMatches[1]);
                } else {
                    $targetValues = [self::normalizeHexadecimalString(substr($targetString, 1, -1))];
                }

                $start = self::normalizeHexadecimalString($matchBFRange['start']);
                $end = self::normalizeHexadecimalString($matchBFRange['end']);
                $bfCharRangeInfo[$beginBFRangePos][] = new BFRange(
                    (int) hexdec($start),
                    (int) hexdec($end),
                    $targetValues,
                    intdiv(strlen($start), 2),
                );
            }
            $lastPos = $endBFRangePos;
        }

        ksort($bfCharRangeInfo); // Make sure that Char and Range are in order they occur in the CMap
        return new ToUnicodeCMap(
            $codeSpaceRanges,
            $byteSize !== null ? $byteSize : 2,
            ...array_merge(...$bfCharRangeInfo),
        );
    }

    private static function normalizeHexadecimalString(string $hexString): string {
        $hexString = str_replace(["\0", "\t", "\n", "\f", "\r", ' '], '', $hexString);
        if (!ctype_xdigit($hexString)) {
            throw new ParseFailureException('Invalid hexadecimal string in CMap');
        }

        return strlen($hexString) % 2 === 0 ? $hexString : $hexString . '0';
    }
}
