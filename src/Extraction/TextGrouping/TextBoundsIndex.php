<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Extraction\TextGrouping;

/** @internal */
readonly class TextBoundsIndex {
    /** @param array{float, float, float, float} $bounds */
    private function __construct(
        private array $bounds,
        private int $minimumIndex,
        private ?int $index,
        private ?self $first,
        private ?self $second,
    ) {}

    /** @param list<array{float, float, float, float}> $bounds */
    public static function build(array $bounds): ?self {
        return $bounds === [] ? null : self::partition($bounds, array_keys($bounds));
    }

    /**
     * @param list<array{float, float, float, float}> $bounds
     * @param non-empty-list<int> $indices
     */
    private static function partition(array $bounds, array $indices): self {
        if (count($indices) === 1) {
            return new self($bounds[$indices[0]], $indices[0], $indices[0], null, null);
        }
        $left = $bottom = $minimumX = $minimumY = INF;
        $right = $top = $maximumX = $maximumY = -INF;
        foreach ($indices as $index) {
            [$x1, $x2, $y1, $y2] = $bounds[$index];
            $left = min($left, $x1);
            $right = max($right, $x2);
            $bottom = min($bottom, $y1);
            $top = max($top, $y2);
            $minimumX = min($minimumX, $x1 + $x2);
            $maximumX = max($maximumX, $x1 + $x2);
            $minimumY = min($minimumY, $y1 + $y2);
            $maximumY = max($maximumY, $y1 + $y2);
        }
        $axis = $maximumX - $minimumX >= $maximumY - $minimumY ? 0 : 2;
        usort($indices, static fn(int $a, int $b): int => ($bounds[$a][$axis] + $bounds[$a][$axis + 1]) <=> ($bounds[$b][$axis] + $bounds[$b][$axis + 1]));
        $middle = intdiv(count($indices), 2);
        return new self(
            [$left, $right, $bottom, $top],
            min($indices),
            null,
            self::partition($bounds, [$indices[0], ...array_slice($indices, 1, $middle - 1)]),
            self::partition($bounds, [$indices[$middle], ...array_slice($indices, $middle + 1)]),
        );
    }

    /**
     * @param array{float, float, float, float} $bounds
     * @return iterable<int>
     */
    public function overlappingBefore(array $bounds, int $before): iterable {
        if ($this->minimumIndex >= $before
            || $bounds[0] > $this->bounds[1] || $bounds[1] < $this->bounds[0]
            || $bounds[2] > $this->bounds[3] || $bounds[3] < $this->bounds[2]) {
            return;
        }
        if ($this->index !== null) {
            yield $this->index;
            return;
        }
        yield from $this->first?->overlappingBefore($bounds, $before) ?? [];
        yield from $this->second?->overlappingBefore($bounds, $before) ?? [];
    }
}
