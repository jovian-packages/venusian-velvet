<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\TypographyException;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Path;

/**
 * A 'CFF ' table read for its outlines (Adobe Technical Note #5176, the Compact Font Format, and
 * #5177, the Type 2 charstring format): the header, the Name, Top DICT, String and Global Subr
 * INDEXes, the CharStrings, the Private DICT and its local subrs; for a CID-keyed font the FDArray
 * and FDSelect (formats 0 and 3), each glyph taking its Font DICT's Private DICT and subrs.
 *
 * Each glyph's Type 2 charstring is run as written: every path operator, flex included; hints
 * parsed and passed over (an unhinted reader draws the same outline), hintmask and cntrmask with
 * their mask bytes counted from the stems so far; subroutine calls with their bias; a width as the
 * first stack value where the operator allows one; the arithmetic and storage operators; endchar's
 * seac form, the base and accent glyphs found through the StandardEncoding and the charset. The
 * outline comes out as cubic curves in font units, y up, each contour closed: the Path the TrueType
 * reader gives, so glyph drivers need nothing new.
 *
 * The FontMatrix is applied as FreeType applies it, so the outline is in the units FreeType and
 * HarfBuzz give: the Top DICT's matrix divided by its own scale (a plain scale, of any em, leaves the
 * charstring's coordinates as they are; a slant or an offset stays), and for a CID-keyed font each
 * Font DICT's em against the Top DICT's (the same em: no change).
 *
 * Refused, as a TypographyException naming the font: a table cut short or out of shape, a stack past
 * 48 values, subroutines nested past 10, an operator Type 2 does not have, 'random' (a charstring
 * that draws differently each time), and a FontMatrix that flattens the plane.
 */
final class CompactFontFormat
{
    /** The Type 2 argument stack holds at most this many values. */
    private const int STACK = 48;

    /** Subroutines nest at most this deep. */
    private const int DEPTH = 10;

    /** The transient array of the storage operators. */
    private const int TRANSIENT = 32;

    /** StandardEncoding: character code => SID (0 for none), for seac. */
    private const array STANDARD = [
        32 => 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
        31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60,
        61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90,
        91, 92, 93, 94, 95,
        161 => 96, 97, 98, 99, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110,
        177 => 111, 112, 113, 114,
        182 => 115, 116, 117, 118, 119, 120, 121, 122,
        191 => 123,
        193 => 124, 125, 126, 127, 128, 129, 130, 131,
        202 => 132, 133,
        205 => 134, 135, 136, 137,
        225 => 138,
        227 => 139,
        232 => 140, 141, 142, 143,
        241 => 144,
        245 => 145,
        248 => 146, 147, 148, 149,
    ];

    private readonly string $data;

    /** @var array{int, int, int} count, offset size, where its offsets start */
    private readonly array $charStrings;

    /** @var array{int, int, int} */
    private readonly array $globalSubrs;

    private readonly int $globalBias;

    /** @var array{int, int, int}|null the Private DICT's local subrs, for a font that is not CID-keyed */
    private readonly ?array $localSubrs;

    /** @var list<array{array{int, int, int}|null, int, Affine|null}> per Font DICT: its local subrs, their bias and the matrix its glyphs take */
    private readonly array $fonts;

    /** The matrix a glyph of a font that is not CID-keyed takes; null for none. */
    private readonly ?Affine $matrix;

    /** Where FDSelect starts, for a CID-keyed font; -1 for one that is not. */
    private readonly int $fdSelect;

    private readonly int $charsetOffset;

    /** @var array<int, int>|null SID => glyph, read at the first seac */
    private ?array $charset = null;

    // the charstring being run
    private Path $path;

    /** @var list<float> */
    private array $stack = [];

    /** @var array<int, float> */
    private array $transient = [];

    private float $x = 0.0;

    private float $y = 0.0;

    private bool $open = false;

    private int $stems = 0;

    private bool $widthTaken = false;

    /** @var array{int, int, int}|null */
    private ?array $local = null;

    private int $localBias = 0;

    /**
     * @param  string  $name  The font, as errors name it.
     *
     * @throws TypographyException When the table is not a CFF font this reader can draw.
     */
    public function __construct(string $data, private readonly string $name)
    {
        $this->data = $data;
        if (strlen($data) < 4 || ord($data[0]) !== 1) {
            $this->refuse('it is not a CFF table of major version 1');
        }
        $at = ord($data[2]);
        [$names, $at] = $this->index($at);
        [$tops, $at] = $this->index($at);
        [, $at] = $this->index($at);      // strings: names are not needed to draw
        [$this->globalSubrs] = $this->index($at);
        $this->globalBias = self::bias($this->globalSubrs[0]);
        if ($names[0] < 1 || $tops[0] < 1) {
            $this->refuse('it has no font in it');
        }
        [$from, $to] = $this->item($tops, 0);
        $top = $this->dict($from, $to);

        if ((int) ($top[1206][0] ?? 2) !== 2) {
            $this->refuse('its charstrings are not Type 2');
        }
        if (! isset($top[17])) {
            $this->refuse('its Top DICT names no CharStrings');
        }
        [$this->charStrings] = $this->index((int) $top[17][0]);
        $this->charsetOffset = (int) ($top[15][0] ?? 0);
        $topMatrix = $top[1207] ?? null;
        [$shape, $topEm] = $this->normalized($topMatrix ?? [0.001, 0.0, 0.0, 0.001, 0.0, 0.0]);

        if (isset($top[1230])) {
            // CID-keyed: each glyph's Font DICT gives its Private DICT
            if (! isset($top[1236], $top[1237])) {
                $this->refuse('it is CID-keyed and has no FDArray or no FDSelect');
            }
            [$array] = $this->index((int) $top[1236][0]);
            $fonts = [];
            for ($i = 0; $i < $array[0]; $i++) {
                [$from, $to] = $this->item($array, $i);
                $font = $this->dict($from, $to);
                [, $subEm] = $this->normalized($font[1207] ?? [0.001, 0.0, 0.0, 0.001, 0.0, 0.0]);
                if (! is_null($topMatrix)) {
                    // FreeType: a Top DICT matrix multiplies the Font DICT's, in ems held as whole units
                    $scaling = $topEm > 1 && $subEm > 1 ? min($topEm, $subEm) : 1.0;
                    $subEm = $subEm * $topEm / $scaling;
                }
                $ratio = $topEm / $subEm;
                $fonts[] = [...$this->privateSubrs($font), self::affine([$shape[0] * $ratio, $shape[1] * $ratio, $shape[2] * $ratio, $shape[3] * $ratio, $shape[4], $shape[5]])];
            }
            $this->fonts = $fonts;
            $this->fdSelect = (int) $top[1237][0];
            $this->localSubrs = null;
            $this->matrix = null;
            $format = $this->u8($this->fdSelect);
            if ($format !== 0 && $format !== 3) {
                $this->refuse("its FDSelect is format {$format}; CFF has formats 0 and 3");
            }
        } else {
            $this->matrix = self::affine($shape);
            $this->fonts = [];
            $this->fdSelect = -1;
            [$this->localSubrs] = $this->privateSubrs($top);
        }
    }

    public function glyphCount(): int
    {
        return $this->charStrings[0];
    }

    /**
     * The glyph's outline in font units, y up, cubic, each contour closed; empty for a glyph that
     * draws nothing.
     *
     * @throws TypographyException When the glyph is not in the font, or its charstring is malformed.
     */
    public function outline(int $glyph): Path
    {
        if ($glyph < 0 || $glyph >= $this->charStrings[0]) {
            throw new TypographyException("{$this->name} has {$this->charStrings[0]} CFF glyphs; there is no glyph {$glyph}.");
        }
        $this->path = new Path();
        $this->draw($glyph, 0.0, 0.0, true);
        $matrix = $this->fdSelect < 0 ? $this->matrix : $this->subrsFor($glyph)[2];

        return is_null($matrix) || $this->path->isEmpty() ? $this->path : $this->path->transformed($matrix);
    }

    /** One glyph's charstring run into the path, moved by (dx, dy); seac may run two more. */
    private function draw(int $glyph, float $dx, float $dy, bool $seacAllowed): void
    {
        [$this->local, $this->localBias] = $this->subrsFor($glyph);
        $this->stack = [];
        $this->transient = [];
        $this->x = $dx;
        $this->y = $dy;
        $this->open = false;
        $this->stems = 0;
        $this->widthTaken = false;
        [$from, $to] = $this->item($this->charStrings, $glyph);
        $seac = $this->run($from, $to, 0);
        if ($this->open) {
            $this->path->close();
            $this->open = false;
        }
        if (! is_array($seac)) {
            return;
        }
        if (! $seacAllowed || $this->fdSelect >= 0) {
            $this->refuse("glyph {$glyph} uses endchar's accent form ".($this->fdSelect >= 0 ? 'in a CID-keyed font' : 'inside an accent'));
        }
        [$adx, $ady, $base, $accent] = $seac;
        $this->draw($this->standardGlyph($base, $glyph), $dx, $dy, false);
        $this->draw($this->standardGlyph($accent, $glyph), $dx + $adx, $dy + $ady, false);
    }

    /**
     * Runs charstring bytes $from..$to.
     *
     * @return array{float, float, int, int}|false|null false when endchar was met, an array for endchar's
     *                                                  accent form (adx, ady, bchar, achar), null at the end of the bytes or a return
     */
    private function run(int $from, int $to, int $depth): array|false|null
    {
        $d = $this->data;
        $at = $from;
        while ($at < $to) {
            $b0 = ord($d[$at]);
            // operands
            if ($b0 >= 32 || $b0 === 28) {
                if (count($this->stack) >= self::STACK) {
                    $this->refuse('a charstring has more than '.self::STACK.' values on its stack');
                }
                if ($b0 <= 246 && $b0 >= 32) {
                    $this->stack[] = (float) ($b0 - 139);
                    $at++;
                } elseif ($b0 <= 250 && $b0 >= 247) {
                    $this->need($at + 2, $to);
                    $this->stack[] = (float) (($b0 - 247) * 256 + ord($d[$at + 1]) + 108);
                    $at += 2;
                } elseif ($b0 <= 254 && $b0 >= 251) {
                    $this->need($at + 2, $to);
                    $this->stack[] = (float) (-($b0 - 251) * 256 - ord($d[$at + 1]) - 108);
                    $at += 2;
                } elseif ($b0 === 28) {
                    $this->need($at + 3, $to);
                    $v = (ord($d[$at + 1]) << 8) | ord($d[$at + 2]);
                    $this->stack[] = (float) ($v >= 0x8000 ? $v - 0x10000 : $v);
                    $at += 3;
                } else {
                    // 255: a 16.16 fixed-point number
                    $this->need($at + 5, $to);
                    $v = unpack('N', $d, $at + 1)[1];
                    $this->stack[] = ($v >= 0x80000000 ? $v - 0x100000000 : $v) / 65536.0;
                    $at += 5;
                }

                continue;
            }
            $at++;
            if ($b0 === 12) {
                $this->need($at + 1, $to);
                $op = 1200 + ord($d[$at]);
                $at++;
            } else {
                $op = $b0;
            }
            $s = &$this->stack;
            switch ($op) {
                case 1:     // hstem
                case 3:     // vstem
                case 18:    // hstemhm
                case 23:    // vstemhm
                    $this->width(count($s) % 2 === 1);
                    $this->stems += intdiv(count($s), 2);
                    $s = [];
                    break;
                case 19:    // hintmask
                case 20:    // cntrmask
                    // values before it are vstems, as vstemhm would take them
                    if ($s !== []) {
                        $this->width(count($s) % 2 === 1);
                        $this->stems += intdiv(count($s), 2);
                    }
                    $this->widthTaken = true;
                    $s = [];
                    $at += intdiv($this->stems + 7, 8);
                    if ($at > $to) {
                        $this->refuse('a hintmask runs past its charstring');
                    }
                    break;
                case 21:    // rmoveto
                    $this->width(count($s) > 2);
                    $this->args(2);
                    $this->moveTo($this->x + $s[0], $this->y + $s[1]);
                    $s = [];
                    break;
                case 22:    // hmoveto
                    $this->width(count($s) > 1);
                    $this->args(1);
                    $this->moveTo($this->x + $s[0], $this->y);
                    $s = [];
                    break;
                case 4:     // vmoveto
                    $this->width(count($s) > 1);
                    $this->args(1);
                    $this->moveTo($this->x, $this->y + $s[0]);
                    $s = [];
                    break;
                case 5:     // rlineto
                    $this->pairs(2);
                    for ($i = 0, $n = count($s); $i < $n; $i += 2) {
                        $this->lineTo($this->x + $s[$i], $this->y + $s[$i + 1]);
                    }
                    $s = [];
                    break;
                case 6:     // hlineto
                case 7:     // vlineto
                    $this->args(1, false);
                    $horizontal = $op === 6;
                    foreach ($s as $v) {
                        $horizontal ? $this->lineTo($this->x + $v, $this->y) : $this->lineTo($this->x, $this->y + $v);
                        $horizontal = ! $horizontal;
                    }
                    $s = [];
                    break;
                case 8:     // rrcurveto
                    $this->pairs(6);
                    for ($i = 0, $n = count($s); $i < $n; $i += 6) {
                        $this->curve($s[$i], $s[$i + 1], $s[$i + 2], $s[$i + 3], $s[$i + 4], $s[$i + 5]);
                    }
                    $s = [];
                    break;
                case 27:    // hhcurveto
                case 26:    // vvcurveto
                    $n = count($s);
                    $first = $n % 4 === 1 ? $s[0] : 0.0;
                    $i = $n % 4 === 1 ? 1 : 0;
                    if ($n - $i < 4 || ($n - $i) % 4 !== 0) {
                        $this->refuse(($op === 27 ? 'hhcurveto' : 'vvcurveto')." takes 4n or 4n + 1 values, got {$n}");
                    }
                    for (; $i < $n; $i += 4) {
                        $op === 27
                            ? $this->curve($s[$i], $first, $s[$i + 1], $s[$i + 2], $s[$i + 3], 0.0)
                            : $this->curve($first, $s[$i], $s[$i + 1], $s[$i + 2], 0.0, $s[$i + 3]);
                        $first = 0.0;
                    }
                    $s = [];
                    break;
                case 31:    // hvcurveto
                case 30:    // vhcurveto
                    $n = count($s);
                    if ($n < 4 || ($n % 4 !== 0 && $n % 4 !== 1)) {
                        $this->refuse(($op === 31 ? 'hvcurveto' : 'vhcurveto')." takes 4n or 4n + 1 values, got {$n}");
                    }
                    $horizontal = $op === 31;
                    for ($i = 0; $i + 4 <= $n; $i += 4) {
                        $last = $i + 5 === $n ? $s[$i + 4] : 0.0;
                        $horizontal
                            ? $this->curve($s[$i], 0.0, $s[$i + 1], $s[$i + 2], $last, $s[$i + 3])
                            : $this->curve(0.0, $s[$i], $s[$i + 1], $s[$i + 2], $s[$i + 3], $last);
                        $horizontal = ! $horizontal;
                    }
                    $s = [];
                    break;
                case 24:    // rcurveline
                    $n = count($s);
                    if ($n < 8 || ($n - 2) % 6 !== 0) {
                        $this->refuse("rcurveline takes 6n + 2 values, got {$n}");
                    }
                    for ($i = 0; $i < $n - 2; $i += 6) {
                        $this->curve($s[$i], $s[$i + 1], $s[$i + 2], $s[$i + 3], $s[$i + 4], $s[$i + 5]);
                    }
                    $this->lineTo($this->x + $s[$n - 2], $this->y + $s[$n - 1]);
                    $s = [];
                    break;
                case 25:    // rlinecurve
                    $n = count($s);
                    if ($n < 8 || ($n - 6) % 2 !== 0) {
                        $this->refuse("rlinecurve takes 2n + 6 values, got {$n}");
                    }
                    for ($i = 0; $i < $n - 6; $i += 2) {
                        $this->lineTo($this->x + $s[$i], $this->y + $s[$i + 1]);
                    }
                    $this->curve($s[$n - 6], $s[$n - 5], $s[$n - 4], $s[$n - 3], $s[$n - 2], $s[$n - 1]);
                    $s = [];
                    break;
                case 1235:  // flex: two curves, the depth value passed over
                    $this->args(13);
                    $this->curve($s[0], $s[1], $s[2], $s[3], $s[4], $s[5]);
                    $this->curve($s[6], $s[7], $s[8], $s[9], $s[10], $s[11]);
                    $s = [];
                    break;
                case 1234:  // hflex
                    $this->args(7);
                    $y0 = $this->y;
                    $this->curve($s[0], 0.0, $s[1], $s[2], $s[3], 0.0);
                    $this->curve($s[4], 0.0, $s[5], $y0 - $this->y, $s[6], 0.0);
                    $s = [];
                    break;
                case 1236:  // hflex1
                    $this->args(9);
                    $y0 = $this->y;
                    $this->curve($s[0], $s[1], $s[2], $s[3], $s[4], 0.0);
                    // the second curve ends on the first's starting height
                    $this->curve($s[5], 0.0, $s[6], $s[7], $s[8], $y0 - ($this->y + $s[7]));
                    $s = [];
                    break;
                case 1237:  // flex1
                    $this->args(11);
                    [$x0, $y0] = [$this->x, $this->y];
                    $dx = $s[0] + $s[2] + $s[4] + $s[6] + $s[8];
                    $dy = $s[1] + $s[3] + $s[5] + $s[7] + $s[9];
                    $this->curve($s[0], $s[1], $s[2], $s[3], $s[4], $s[5]);
                    // the last step is d6 along the longer way; the other way ends where the flex began
                    abs($dx) > abs($dy)
                        ? $this->curve($s[6], $s[7], $s[8], $s[9], $s[10], $y0 - ($this->y + $s[7] + $s[9]))
                        : $this->curve($s[6], $s[7], $s[8], $s[9], $x0 - ($this->x + $s[6] + $s[8]), $s[10]);
                    $s = [];
                    break;
                case 10:    // callsubr
                case 29:    // callgsubr
                    if ($s === []) {
                        $this->refuse('a subroutine call has no number');
                    }
                    $local = $op === 10;
                    $index = (int) array_pop($s) + ($local ? $this->localBias : $this->globalBias);
                    $subrs = $local ? $this->local : $this->globalSubrs;
                    if (is_null($subrs) || $index < 0 || $index >= $subrs[0]) {
                        $this->refuse(($local ? 'local' : 'global')." subroutine {$index} is not in the font");
                    }
                    if ($depth + 1 > self::DEPTH) {
                        $this->refuse('subroutines nest more than '.self::DEPTH.' deep');
                    }
                    [$subFrom, $subTo] = $this->item($subrs, $index);
                    $ended = $this->run($subFrom, $subTo, $depth + 1);
                    if ($ended !== null) {
                        return $ended;
                    }
                    break;
                case 11:    // return
                    return null;
                case 14:    // endchar
                    $n = count($s);
                    $this->width($n === 1 || $n === 5);
                    if ($this->open) {
                        $this->path->close();
                        $this->open = false;
                    }
                    if (count($s) === 4) {
                        return [$s[0], $s[1], (int) $s[2], (int) $s[3]];
                    }
                    if ($s !== []) {
                        $this->refuse('endchar takes 0 or 4 values, got '.count($s));
                    }

                    return false;
                // arithmetic and storage
                case 1203: $this->binary(fn ($a, $b) => ($a != 0 && $b != 0) ? 1.0 : 0.0); break;   // and
                case 1204: $this->binary(fn ($a, $b) => ($a != 0 || $b != 0) ? 1.0 : 0.0); break;   // or
                case 1205: $this->unary(fn ($a) => $a == 0 ? 1.0 : 0.0); break;                       // not
                case 1209: $this->unary(fn ($a) => abs($a)); break;                                  // abs
                case 1210: $this->binary(fn ($a, $b) => $a + $b); break;                             // add
                case 1211: $this->binary(fn ($a, $b) => $a - $b); break;                             // sub
                case 1212:                                                                            // div
                    $this->binary(fn ($a, $b) => $b == 0 ? $this->refuse('a charstring divides by 0') : $a / $b);
                    break;
                case 1214: $this->unary(fn ($a) => -$a); break;                                      // neg
                case 1215: $this->binary(fn ($a, $b) => $a == $b ? 1.0 : 0.0); break;              // eq
                case 1218: $this->args(1, false); array_pop($s); break;                              // drop
                case 1220:                                                                            // put
                    $this->args(2, false);
                    $i = (int) array_pop($s);
                    $v = array_pop($s);
                    $this->slot($i);
                    $this->transient[$i] = $v;
                    break;
                case 1221:                                                                            // get
                    $this->args(1, false);
                    $i = (int) array_pop($s);
                    $this->slot($i);
                    $s[] = $this->transient[$i] ?? 0.0;
                    break;
                case 1222:                                                                            // ifelse
                    $this->args(4, false);
                    [$s1, $s2, $v1, $v2] = array_splice($s, -4);
                    $s[] = $v1 <= $v2 ? $s1 : $s2;
                    break;
                case 1223:
                    $this->refuse("a charstring asks for 'random', which draws a glyph differently each time");
                case 1224: $this->binary(fn ($a, $b) => $a * $b); break;                             // mul
                case 1226:                                                                            // sqrt
                    $this->unary(fn ($a) => $a < 0 ? $this->refuse('a charstring takes the square root of a negative number') : sqrt($a));
                    break;
                case 1227: $this->args(1, false); $this->push(end($s)); break;                       // dup
                case 1228:                                                                            // exch
                    $this->args(2, false);
                    [$a, $b] = array_splice($s, -2);
                    array_push($s, $b, $a);
                    break;
                case 1229:                                                                            // index
                    $this->args(1, false);
                    $i = (int) array_pop($s);
                    $n = count($s);
                    // a negative i copies the top; one past the bottom is refused
                    $from = $i < 0 ? $n - 1 : $n - 1 - $i;
                    $this->push($from >= 0 ? $s[$from] : $this->refuse('index reaches past the stack'));
                    break;
                case 1230:                                                                            // roll
                    $this->args(2, false);
                    $j = (int) array_pop($s);
                    $n = (int) array_pop($s);
                    if ($n < 0 || $n > count($s)) {
                        $this->refuse('roll reaches past the stack');
                    }
                    if ($n > 0) {
                        $top = array_splice($s, -$n);
                        $j = (($j % $n) + $n) % $n;
                        array_push($s, ...array_slice($top, $n - $j), ...array_slice($top, 0, $n - $j));
                    }
                    break;
                default:
                    $this->refuse('a charstring has operator '.($op >= 1200 ? '12 '.($op - 1200) : $op).', which Type 2 does not have');
            }
            unset($s);
        }

        return null;
    }

    /** The first stack-clearing operator may carry the advance width first: it is taken off. */
    private function width(bool $extra): void
    {
        if (! $this->widthTaken && $extra) {
            array_shift($this->stack);
        }
        $this->widthTaken = true;
    }

    private function moveTo(float $x, float $y): void
    {
        if ($this->open) {
            $this->path->close();
        }
        $this->path->moveTo($x, $y);
        [$this->x, $this->y, $this->open] = [$x, $y, true];
    }

    private function lineTo(float $x, float $y): void
    {
        $this->opened();
        $this->path->lineTo($x, $y);
        [$this->x, $this->y] = [$x, $y];
    }

    /** A curve by three relative steps from the current point. */
    private function curve(float $dx1, float $dy1, float $dx2, float $dy2, float $dx3, float $dy3): void
    {
        $this->opened();
        $x1 = $this->x + $dx1;
        $y1 = $this->y + $dy1;
        $x2 = $x1 + $dx2;
        $y2 = $y1 + $dy2;
        $x3 = $x2 + $dx3;
        $y3 = $y2 + $dy3;
        $this->path->cubicTo($x1, $y1, $x2, $y2, $x3, $y3);
        [$this->x, $this->y] = [$x3, $y3];
    }

    /** A drawing operator before any moveto starts at the current point, as Adobe's engine does. */
    private function opened(): void
    {
        if (! $this->open) {
            $this->path->moveTo($this->x, $this->y);
            $this->open = true;
        }
    }

    /** @param  callable(float, float): float  $f */
    private function binary(callable $f): void
    {
        $this->args(2, false);
        $b = array_pop($this->stack);
        $a = array_pop($this->stack);
        $this->stack[] = (float) $f($a, $b);
    }

    /** @param  callable(float): float  $f */
    private function unary(callable $f): void
    {
        $this->args(1, false);
        $this->stack[] = (float) $f(array_pop($this->stack));
    }

    private function push(float $v): void
    {
        if (count($this->stack) >= self::STACK) {
            $this->refuse('a charstring has more than '.self::STACK.' values on its stack');
        }
        $this->stack[] = $v;
    }

    private function slot(int $i): void
    {
        if ($i < 0 || $i >= self::TRANSIENT) {
            $this->refuse("a charstring reaches transient slot {$i}; there are ".self::TRANSIENT);
        }
    }

    /** Exactly $n values on the stack; with $exact false, at least $n. */
    private function args(int $n, bool $exact = true): void
    {
        $count = count($this->stack);
        if ($exact ? $count !== $n : $count < $n) {
            $this->refuse("an operator takes {$n} values, got {$count}");
        }
    }

    /** A whole, non-zero number of groups of $n values. */
    private function pairs(int $n): void
    {
        $count = count($this->stack);
        if ($count === 0 || $count % $n !== 0) {
            $this->refuse("an operator takes a multiple of {$n} values, got {$count}");
        }
    }

    private function need(int $end, int $to): void
    {
        if ($end > $to) {
            $this->refuse('a charstring is cut short in a number');
        }
    }

    /** The glyph a StandardEncoding code names, through the charset; for seac. */
    private function standardGlyph(int $code, int $glyph): int
    {
        $sid = self::STANDARD[$code] ?? 0;
        if ($sid === 0) {
            $this->refuse("glyph {$glyph}'s accent form names code {$code}, which StandardEncoding has no glyph for");
        }
        $this->charset ??= $this->readCharset();

        return $this->charset[$sid] ?? $this->refuse("glyph {$glyph}'s accent form names a glyph the font does not have");
    }

    /** @return array<int, int> SID => glyph */
    private function readCharset(): array
    {
        $count = $this->charStrings[0];
        $map = [0 => 0];
        if ($this->charsetOffset === 0) {
            // ISOAdobe: glyph i is SID i
            for ($g = 1; $g < min($count, 229); $g++) {
                $map[$g] = $g;
            }

            return $map;
        }
        if ($this->charsetOffset < 3) {
            $this->refuse('its accented glyphs need the Expert charset, which this reader does not carry');
        }
        $at = $this->charsetOffset;
        $format = $this->u8($at++);
        $g = 1;
        while ($g < $count) {
            if ($format === 0) {
                $map[$this->u16($at)] ??= $g;
                $at += 2;
                $g++;

                continue;
            }
            if ($format !== 1 && $format !== 2) {
                $this->refuse("its charset is format {$format}; CFF has 0, 1 and 2");
            }
            $first = $this->u16($at);
            $left = $format === 1 ? $this->u8($at + 2) : $this->u16($at + 2);
            $at += $format === 1 ? 3 : 4;
            for ($i = 0; $i <= $left && $g < $count; $i++, $g++) {
                $map[$first + $i] ??= $g;
            }
        }

        return $map;
    }

    /**
     * The local subrs a glyph runs with, and their bias: its Font DICT's for a CID-keyed font.
     *
     * @return array{array{int, int, int}|null, int, Affine|null}
     */
    private function subrsFor(int $glyph): array
    {
        if ($this->fdSelect < 0) {
            return [$this->localSubrs, is_null($this->localSubrs) ? 0 : self::bias($this->localSubrs[0]), $this->matrix];
        }
        $at = $this->fdSelect;
        if ($this->u8($at) === 0) {
            $fd = $this->u8($at + 1 + $glyph);
        } else {
            $ranges = $this->u16($at + 1);
            if ($ranges < 1) {
                $this->refuse('its FDSelect has no ranges');
            }
            [$low, $high] = [0, $ranges - 1];
            $fd = -1;
            // ranges are [first glyph u16, fd u8] in order, then a sentinel glyph
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                $first = $this->u16($at + 3 + 3 * $mid);
                $next = $this->u16($at + 3 + 3 * ($mid + 1));
                if ($glyph < $first) {
                    $high = $mid - 1;
                } elseif ($glyph >= $next) {
                    $low = $mid + 1;
                } else {
                    $fd = $this->u8($at + 5 + 3 * $mid);
                    break;
                }
            }
            if ($fd < 0) {
                $this->refuse("its FDSelect gives glyph {$glyph} no Font DICT");
            }
        }
        if ($fd >= count($this->fonts)) {
            $this->refuse("its FDSelect gives glyph {$glyph} Font DICT {$fd}; it has ".count($this->fonts));
        }

        return $this->fonts[$fd];
    }

    /**
     * A DICT's Private DICT read for its local subrs.
     *
     * @param  array<int, list<float>>  $dict
     * @return array{array{int, int, int}|null, int}
     */
    private function privateSubrs(array $dict): array
    {
        if (! isset($dict[18])) {
            return [null, 0];
        }
        // Private takes a size and an offset; with fewer it says nowhere
        if (count($dict[18]) < 2) {
            $this->refuse('its Private DICT lies outside the table');
        }
        [$size, $offset] = [(int) $dict[18][0], (int) $dict[18][1]];
        if ($size < 0 || $offset < 0 || $offset + $size > strlen($this->data)) {
            $this->refuse('its Private DICT lies outside the table');
        }
        $private = $this->dict($offset, $offset + $size);
        if (! isset($private[19]) || $private[19] === []) {
            return [null, 0];
        }
        [$subrs] = $this->index($offset + (int) $private[19][0]);

        return [$subrs, self::bias($subrs[0])];
    }

    /**
     * A FontMatrix as FreeType holds it: divided by its scale (yy, or yx when yy is 0), with the em
     * that scale makes.
     *
     * @param  list<float>  $m
     * @return array{array{float, float, float, float, float, float}, float} the matrix divided by its scale, and the em (1 / the scale)
     */
    private function normalized(array $m): array
    {
        if (count($m) !== 6) {
            $this->refuse('a FontMatrix has '.count($m).' numbers; it has 6');
        }
        $scale = $m[3] != 0 ? abs($m[3]) : abs($m[1]);
        if ($scale == 0 || $m[0] * $m[3] - $m[1] * $m[2] == 0) {
            $this->refuse('a FontMatrix flattens the plane');
        }

        return [[$m[0] / $scale, $m[1] / $scale, $m[2] / $scale, $m[3] / $scale, $m[4] / $scale, $m[5] / $scale], 1.0 / $scale];
    }

    /** The matrix as an Affine; null when it leaves a point where it is (within what a DICT real holds). */
    private static function affine(array $m): ?Affine
    {
        $near = fn (float $a, float $b): bool => abs($a - $b) <= 1e-9;

        return $near($m[0], 1.0) && $near($m[1], 0.0) && $near($m[2], 0.0) && $near($m[3], 1.0) && $near($m[4], 0.0) && $near($m[5], 0.0)
            ? null
            : new Affine($m[0], $m[1], $m[2], $m[3], $m[4], $m[5]);
    }

    /** Subroutine numbers are biased by the count, as Type 2 says. */
    private static function bias(int $count): int
    {
        return $count < 1240 ? 107 : ($count < 33900 ? 1131 : 32768);
    }

    /**
     * An INDEX at $at.
     *
     * @return array{array{int, int, int}, int} [count, offset size, where the offsets start] and where the next structure starts
     */
    private function index(int $at): array
    {
        $count = $this->u16($at);
        if ($count === 0) {
            return [[0, 1, $at + 2], $at + 2];
        }
        $size = $this->u8($at + 2);
        if ($size < 1 || $size > 4) {
            $this->refuse("an INDEX at {$at} has offsets of {$size} bytes");
        }
        $index = [$count, $size, $at + 3];
        $last = $this->offset($index, $count);
        $end = $at + 3 + ($count + 1) * $size + $last - 1;
        if ($end > strlen($this->data)) {
            $this->refuse("an INDEX at {$at} reaches past the table");
        }

        return [$index, $end];
    }

    /**
     * Item $i of an INDEX: where its bytes start and end.
     *
     * @param  array{int, int, int}  $index
     * @return array{int, int}
     */
    private function item(array $index, int $i): array
    {
        [$count, $size, $offsets] = $index;
        $data = $offsets + ($count + 1) * $size - 1;
        $from = $data + $this->offset($index, $i);
        $to = $data + $this->offset($index, $i + 1);
        if ($from > $to || $from <= $data || $to > strlen($this->data)) {
            $this->refuse("item {$i} of an INDEX at ".($offsets - 3).' lies outside it');
        }

        return [$from, $to];
    }

    /** @param  array{int, int, int}  $index */
    private function offset(array $index, int $i): int
    {
        [, $size, $offsets] = $index;
        $at = $offsets + $i * $size;
        if ($at + $size > strlen($this->data)) {
            $this->refuse('an INDEX is cut short');
        }
        $v = 0;
        for ($k = 0; $k < $size; $k++) {
            $v = ($v << 8) | ord($this->data[$at + $k]);
        }

        return $v;
    }

    /**
     * A DICT's bytes: operator => its operands (12 x as 1200 + x).
     *
     * @return array<int, list<float>>
     */
    private function dict(int $from, int $to): array
    {
        $d = $this->data;
        $dict = [];
        $operands = [];
        $at = $from;
        while ($at < $to) {
            $b0 = ord($d[$at]);
            if ($b0 <= 21) {
                $op = $b0;
                $at++;
                if ($b0 === 12) {
                    if ($at >= $to) {
                        $this->refuse('a DICT is cut short in an operator');
                    }
                    $op = 1200 + ord($d[$at++]);
                }
                $dict[$op] = $operands;
                $operands = [];

                continue;
            }
            if ($b0 >= 32 && $b0 <= 246) {
                $operands[] = (float) ($b0 - 139);
                $at++;
            } elseif ($b0 >= 247 && $b0 <= 250) {
                $operands[] = (float) (($b0 - 247) * 256 + $this->byteIn($at + 1, $to) + 108);
                $at += 2;
            } elseif ($b0 >= 251 && $b0 <= 254) {
                $operands[] = (float) (-($b0 - 251) * 256 - $this->byteIn($at + 1, $to) - 108);
                $at += 2;
            } elseif ($b0 === 28) {
                $v = ($this->byteIn($at + 1, $to) << 8) | $this->byteIn($at + 2, $to);
                $operands[] = (float) ($v >= 0x8000 ? $v - 0x10000 : $v);
                $at += 3;
            } elseif ($b0 === 29) {
                $this->byteIn($at + 4, $to);
                $v = unpack('N', $d, $at + 1)[1];
                $operands[] = (float) ($v >= 0x80000000 ? $v - 0x100000000 : $v);
                $at += 5;
            } elseif ($b0 === 30) {
                [$operands[], $at] = $this->real($at + 1, $to);
            } else {
                $this->refuse("a DICT has byte {$b0}, which is neither operand nor operator");
            }
        }

        return $dict;
    }

    /**
     * A DICT real number: nibbles until 0xf.
     *
     * @return array{float, int}
     */
    private function real(int $at, int $to): array
    {
        $text = '';
        while (true) {
            $byte = $this->byteIn($at++, $to);
            foreach ([$byte >> 4, $byte & 0xF] as $nibble) {
                if ($nibble === 0xF) {
                    if ($text === '' || ! is_numeric($text)) {
                        $this->refuse("a DICT real number reads '{$text}'");
                    }

                    return [(float) $text, $at];
                }
                $text .= match ($nibble) { 0xA => '.', 0xB => 'E', 0xC => 'E-', 0xD => $this->refuse('a DICT real number has a reserved nibble'), 0xE => '-', default => (string) $nibble };
            }
        }
    }

    private function byteIn(int $at, int $to): int
    {
        if ($at >= $to) {
            $this->refuse('a DICT is cut short in a number');
        }

        return ord($this->data[$at]);
    }

    private function u8(int $at): int
    {
        if ($at < 0 || $at >= strlen($this->data)) {
            $this->refuse("byte {$at} lies past the table");
        }

        return ord($this->data[$at]);
    }

    private function u16(int $at): int
    {
        if ($at < 0 || $at + 2 > strlen($this->data)) {
            $this->refuse("bytes {$at}..".($at + 2).' lie past the table');
        }

        return (ord($this->data[$at]) << 8) | ord($this->data[$at + 1]);
    }

    /** @return never */
    private function refuse(string $why): never
    {
        throw new TypographyException("{$this->name}: its CFF outlines cannot be read: {$why}.");
    }
}
