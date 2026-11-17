<?php

namespace Jovian\Engines\Velvet\Lustrous\Stroking;

use Surface\Contracts\Rendering\Rasterization\DashGeometry;
use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;

/**
 * A stroked polyline as its outline, to fill NON_ZERO. An open polyline is one contour: out along one side, round
 * the cap at the far end, back along the other side, round the cap at the start. A closed one is two: its two
 * borders, one each way round, so what lies between them is filled and what the inner one holds is not.
 *
 * At each corner, the side on the outside of the turn takes the join (a miter, cut to a bevel where the tip would
 * sit more than the miter limit of half-strokes from the corner; a bevel; or a round arc). The side on the inside
 * runs to the corner itself and out again: the two offset edges cross there, and the small loop that leaves winds
 * the same way as the outline, so NON_ZERO fills it with the rest. Every contour runs the same way round its
 * stroke, so strokes that overlap unite instead of cancelling.
 *
 * Lustrous's stroker in PHP, needing nothing ('lustrous-php'); CLandStroker answers the same numbers in C.
 */
final class PHPStroker implements DashGeometry
{
    /** A round join or cap strays from its arc by less than this many pixels. */
    private const float FLATNESS = 0.1;

    /**
     * @param  list<array{float, float}>  $points
     * @param  array{float, float}|null  $direction  The way a zero-length stroke runs, for the orientation of a square cap; none: along x.
     * @return list<list<float>> Flat x, y, x, y… contours.
     */
    public function outline(array $points, float $stroke, bool $closed, StrokeCap $cap = StrokeCap::BUTT, StrokeJoin $join = StrokeJoin::MITER, float $miterLimit = self::MITER_LIMIT, ?array $direction = null): array
    {
        if (! ($stroke > 0)) {
            return [];
        }
        $half = $stroke / 2;

        $p = [];
        foreach ($points as [$x, $y]) {
            if ($p === [] || $p[count($p) - 1] !== [(float) $x, (float) $y]) {
                $p[] = [(float) $x, (float) $y];
            }
        }
        if ($closed && count($p) > 2 && $p[0] === $p[count($p) - 1]) {
            array_pop($p);
        }
        $n = count($p);
        if ($n < 2) {
            // a stroke that was drawn but has no length is a dot under a round cap and a square under a square one (SVG 2); none under a butt cap
            return $n === 1 && count($points) >= 2 && $cap !== StrokeCap::BUTT ? [self::dot($p[0], $half, $cap, $direction ?? [1.0, 0.0])] : [];
        }
        $closed = $closed && $n > 2;
        $segments = $closed ? $n : $n - 1;

        $directions = [];
        $lengths = [];
        for ($i = 0; $i < $segments; $i++) {
            [$ax, $ay] = $p[$i];
            [$bx, $by] = $p[($i + 1) % $n];
            $length = sqrt(($bx - $ax) * ($bx - $ax) + ($by - $ay) * ($by - $ay));
            $directions[] = [($bx - $ax) / $length, ($by - $ay) / $length];
            $lengths[] = $length;
        }

        // the two borders, each in the order the polyline runs: -1 is the side to the right of the way it runs
        $right = self::border($p, $directions, $lengths, $closed, -1.0, $half, $join, $miterLimit);
        $left = self::border($p, $directions, $lengths, $closed, 1.0, $half, $join, $miterLimit);

        if ($closed) {
            return [$right, self::reverse($left)];
        }

        // out along the right, round the far end, back along the left, round the start
        $last = $directions[$segments - 1];
        $outline = $right;
        array_push($outline, ...self::cap($p[$n - 1], $last, $half, $cap));
        array_push($outline, ...self::reverse($left));
        array_push($outline, ...self::cap($p[0], [-$directions[0][0], -$directions[0][1]], $half, $cap));

        return [$outline];
    }

    /**
     * One border of the stroke: the polyline moved $half to one side, in the order the polyline runs, with what
     * each corner takes on that side.
     *
     * @param  list<array{float, float}>  $p
     * @param  list<array{float, float}>  $directions  The unit direction of each segment.
     * @param  list<float>  $lengths  The length of each segment.
     * @param  float  $side  1.0: the side of the left normal (-y, x); -1.0: the other.
     * @return list<float>
     */
    private static function border(array $p, array $directions, array $lengths, bool $closed, float $side, float $half, StrokeJoin $join, float $miterLimit): array
    {
        $n = count($p);
        $segments = count($directions);
        if ($closed) {
            $points = [];
            for ($j = 0; $j < $n; $j++) {
                $before = ($j + $segments - 1) % $segments;
                array_push($points, ...self::corner($p[$j], $directions[$before], $directions[$j], min($lengths[$before], $lengths[$j]), $side, $half, $join, $miterLimit));
            }

            return $points;
        }

        $points = [$p[0][0] + $side * -$directions[0][1] * $half, $p[0][1] + $side * $directions[0][0] * $half];
        for ($j = 1; $j < $n - 1; $j++) {
            array_push($points, ...self::corner($p[$j], $directions[$j - 1], $directions[$j], min($lengths[$j - 1], $lengths[$j]), $side, $half, $join, $miterLimit));
        }
        $last = $directions[$segments - 1];
        array_push($points, $p[$n - 1][0] + $side * -$last[1] * $half, $p[$n - 1][1] + $side * $last[0] * $half);

        return $points;
    }

    /**
     * What one border does at a corner, from the edge coming in to the edge going out. On the outside of the turn
     * that is the join. On the inside the two edges cross: where both segments are long enough to reach the
     * crossing it is that one point, and where one is not, the border runs to the corner itself and out again.
     *
     * A point that lies on the straight line between its neighbours is left out: a miter is its tip alone.
     *
     * @param  array{float, float}  $vertex
     * @param  array{float, float}  $in  Unit direction of the segment ending here.
     * @param  array{float, float}  $out  Unit direction of the segment starting here.
     * @param  float  $shorter  The length of the shorter of the two segments.
     * @return list<float>
     */
    private static function corner(array $vertex, array $in, array $out, float $shorter, float $side, float $half, StrokeJoin $join, float $miterLimit): array
    {
        [$vx, $vy] = $vertex;
        $ax = $side * -$in[1] * $half;
        $ay = $side * $in[0] * $half;
        $bx = $side * -$out[1] * $half;
        $by = $side * $out[0] * $half;
        $cross = $in[0] * $out[1] - $in[1] * $out[0];
        if ($cross == 0.0) {
            // straight on: one point. Straight back: across the end, from this side of the way in to this side of the way out.
            return $in[0] * $out[0] + $in[1] * $out[1] > 0 ? [$vx + $ax, $vy + $ay] : [$vx + $ax, $vy + $ay, $vx + $bx, $vy + $by];
        }
        // The turn is toward the left normal when the cross product is positive: that side is the inside.
        if ($side * $cross > 0) {
            // Sum of the two unit normals on this side: its length is 2cos(θ/2), and the two edges' lines
            // cross 2·half / that length from the corner along it.
            $mx = ($ax + $bx) / $half;
            $my = ($ay + $by) / $half;
            $squared = $mx * $mx + $my * $my;
            // the crossing is half·tan(θ/2) back along each segment: within half of each, so two corners
            // on one segment never claim the same stretch of it
            if ($squared > 0.0 && $half * abs($cross) / (1 + $in[0] * $out[0] + $in[1] * $out[1]) <= $shorter / 2) {
                $reach = 2 * $half / $squared;

                return [$vx + $mx * $reach, $vy + $my * $reach];
            }

            return [$vx + $ax, $vy + $ay, $vx, $vy, $vx + $bx, $vy + $by];
        }

        if ($join === StrokeJoin::ROUND) {
            $points = [$vx + $ax, $vy + $ay];
            $from = atan2($ay, $ax);
            $sweep = atan2($by, $bx) - $from;
            while ($sweep > M_PI) {
                $sweep -= 2 * M_PI;
            }
            while ($sweep < -M_PI) {
                $sweep += 2 * M_PI;
            }
            $steps = self::steps(abs($sweep), $half);
            for ($i = 1; $i < $steps; $i++) {
                $angle = $from + $sweep * $i / $steps;
                $points[] = $vx + $half * cos($angle);
                $points[] = $vy + $half * sin($angle);
            }
            array_push($points, $vx + $bx, $vy + $by);

            return $points;
        }
        if ($join === StrokeJoin::BEVEL) {
            return [$vx + $ax, $vy + $ay, $vx + $bx, $vy + $by];
        }

        // Sum of the two unit outer normals: its length is 2cos(θ/2), and the
        // miter tip sits 2·half / that length from the corner along it.
        $mx = ($ax + $bx) / $half;
        $my = ($ay + $by) / $half;
        $squared = $mx * $mx + $my * $my;
        if ($squared * $miterLimit * $miterLimit < 4.0) {
            return [$vx + $ax, $vy + $ay, $vx + $bx, $vy + $by];
        }
        $reach = 2 * $half / $squared;

        // the tip alone: the ends of the two edges lie on the lines into it
        return [$vx + $mx * $reach, $vy + $my * $reach];
    }

    /**
     * What a zero-length stroke draws: a circle the width across (round), or a square the width on a side
     * turned to the direction (square).
     *
     * @param  array{float, float}  $at
     * @param  array{float, float}  $direction  Unit direction.
     * @return list<float>
     */
    private static function dot(array $at, float $half, StrokeCap $cap, array $direction): array
    {
        [$x, $y] = $at;
        if ($cap === StrokeCap::SQUARE) {
            $ux = $direction[0] * $half;
            $uy = $direction[1] * $half;
            $nx = -$uy;
            $ny = $ux;

            // the same way round as every outline, so a dot that overlaps another piece unites with it
            return [$x - $ux - $nx, $y - $uy - $ny, $x + $ux - $nx, $y + $uy - $ny, $x + $ux + $nx, $y + $uy + $ny, $x - $ux + $nx, $y - $uy + $ny];
        }
        // a multiple of four, so the four points straight out are vertices
        $steps = (int) (ceil(self::steps(2 * M_PI, $half) / 4) * 4);
        $points = [];
        for ($i = 0; $i < $steps; $i++) {
            $angle = 2 * M_PI * $i / $steps;
            $points[] = $x + $half * cos($angle);
            $points[] = $y + $half * sin($angle);
        }

        return $points;
    }

    /**
     * A polyline cut into the pieces a dash pattern leaves: the pattern is on, off, on, off… (an odd count is
     * repeated), starting $offset into it. A piece that runs round a corner keeps the corner. An on element of
     * length 0 leaves a zero-length piece: a dot under a round cap, as SVG's "0 10" dash makes a dotted line. A
     * closed polyline is walked once round; a dash that runs across its start is one piece, joined there as at any
     * corner, and a dash that never stops is the whole polyline, closed.
     *
     * @param  list<array{float, float}>  $points
     * @param  list<float>  $dash  Lengths, not negative, with a positive sum.
     * @return list<array{points: list<array{float, float}>, closed: bool, direction: array{float, float}}> direction: the way the piece runs where it ends, for a zero-length one's square cap
     */
    public function dashed(array $points, bool $closed, array $dash, float $offset): array
    {
        $pattern = array_map(floatval(...), $dash);
        if (count($pattern) % 2 === 1) {
            $pattern = [...$pattern, ...$pattern];
        }
        $total = array_sum($pattern);
        if ($total <= 0.0 || max($pattern) <= 1e-12) {
            return [];
        }

        $walk = $points;
        $ring = $closed && count($walk) > 2;
        if ($ring) {
            $walk[] = $walk[0];
        }
        $direction = null;
        for ($i = 0; $i < count($walk) - 1; $i++) {
            $length = hypot($walk[$i + 1][0] - $walk[$i][0], $walk[$i + 1][1] - $walk[$i][1]);
            if ($length > 0.0) {
                $direction = [($walk[$i + 1][0] - $walk[$i][0]) / $length, ($walk[$i + 1][1] - $walk[$i][1]) / $length];
                break;
            }
        }
        if ($direction === null) {
            return [];
        }

        // the pattern's state at the start: which element, and how much of it is left
        $phase = fmod($offset, $total);
        if ($phase < 0) {
            $phase += $total;
        }
        $at = 0;
        while ($phase > $pattern[$at] || ($phase === $pattern[$at] && $pattern[$at] > 0.0)) {
            $phase -= $pattern[$at];
            $at = ($at + 1) % count($pattern);
        }
        $left = $pattern[$at] - $phase;

        $pieces = [];
        $piece = null;
        $x = $walk[0][0];
        $y = $walk[0][1];
        $settle = function () use (&$at, &$left, &$piece, &$pieces, &$x, &$y, &$direction, $pattern): void {
            // elements of no length: an on one is a dot
            while ($left <= 1e-12) {
                if ($at % 2 === 0) {
                    $pieces[] = ['points' => [[$x, $y], [$x, $y]], 'closed' => false, 'direction' => $direction];
                }
                $at = ($at + 1) % count($pattern);
                $left = $pattern[$at];
            }
            if ($at % 2 === 0) {
                $piece = ['points' => [[$x, $y]], 'closed' => false, 'direction' => $direction];
            }
        };
        $settle();
        $startsOn = $piece !== null;
        $startIndex = count($pieces);
        $turnedOff = false;

        for ($i = 0; $i < count($walk) - 1; $i++) {
            [$ax, $ay] = $walk[$i];
            [$bx, $by] = $walk[$i + 1];
            $length = hypot($bx - $ax, $by - $ay);
            if ($length === 0.0) {
                continue;
            }
            $direction = [($bx - $ax) / $length, ($by - $ay) / $length];
            $position = 0.0;
            while ($position < $length) {
                $take = min($left, $length - $position);
                $position += $take;
                $left -= $take;
                $x = $ax + ($bx - $ax) * $position / $length;
                $y = $ay + ($by - $ay) * $position / $length;
                if ($piece !== null) {
                    $piece['points'][] = [$x, $y];
                    $piece['direction'] = $direction;
                }
                if ($left <= 1e-12) {
                    if ($piece !== null) {
                        if (count($piece['points']) >= 2) {
                            $pieces[] = $piece;
                        }
                        $piece = null;
                        $turnedOff = true;
                    }
                    $at = ($at + 1) % count($pattern);
                    $left = $pattern[$at];
                    $settle();
                }
            }
        }

        $endsOn = $piece !== null && count($piece['points']) >= 2;
        if ($ring && $startsOn && $endsOn) {
            if (! $turnedOff) {
                // never stopped: the whole polyline, closed
                return [['points' => $points, 'closed' => true, 'direction' => $direction]];
            }
            // the last dash runs on into the first: one piece, joined at the start
            $first = $pieces[$startIndex];
            $piece['points'] = [...$piece['points'], ...array_slice($first['points'], 1)];
            $piece['direction'] = $first['direction'];
            $pieces[$startIndex] = $piece;
        } elseif ($endsOn) {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * What the outline takes round an end of an open polyline, between its two borders: from the border on the
     * right of the outward direction to the one on its left. The two border points themselves are the borders'.
     * A butt cap is the straight line between them: nothing.
     *
     * @param  array{float, float}  $end
     * @param  array{float, float}  $outward  Unit direction pointing away from the polyline, out of this end.
     * @return list<float>
     */
    private static function cap(array $end, array $outward, float $half, StrokeCap $cap): array
    {
        if ($cap === StrokeCap::BUTT) {
            return [];
        }
        [$x, $y] = $end;
        // the normal on the left of the outward direction
        $nx = -$outward[1] * $half;
        $ny = $outward[0] * $half;
        if ($cap === StrokeCap::SQUARE) {
            $ox = $outward[0] * $half;
            $oy = $outward[1] * $half;

            return [$x - $nx + $ox, $y - $ny + $oy, $x + $nx + $ox, $y + $ny + $oy];
        }
        // a half circle from the right normal, through the outward direction, to the left normal
        $from = atan2(-$ny, -$nx);
        // an even count, so the point straight out from the end is one of the vertices
        $steps = self::steps(M_PI, $half);
        $steps += $steps % 2;
        $points = [];
        for ($i = 1; $i < $steps; $i++) {
            $angle = $from + M_PI * $i / $steps;
            $points[] = $x + $half * cos($angle);
            $points[] = $y + $half * sin($angle);
        }

        return $points;
    }

    /** How many straight pieces an arc of $sweep radians at $radius takes to stray less than FLATNESS. */
    private static function steps(float $sweep, float $radius): int
    {
        if ($radius <= self::FLATNESS) {
            return 2;
        }
        $step = 2 * acos(1 - self::FLATNESS / $radius);

        return max(2, (int) ceil($sweep / $step));
    }

    /**
     * @param  list<float>  $flat
     * @return list<float>
     */
    private static function reverse(array $flat): array
    {
        return array_merge(...array_reverse(array_chunk($flat, 2)));
    }
}
