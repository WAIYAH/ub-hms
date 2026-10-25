<?php
/**
 * Charts for the dashboards, drawn on the server as inline SVG.
 *
 * No chart library and no JavaScript: the strict content security policy forbids both, and an SVG made here also
 * prints, scales and can be tested. Each chart carries a text summary (aria-label) and a hidden data table, so a
 * person who cannot see the chart can still read the numbers. Colour is never the only carrier of meaning: every
 * series has a name, and the values are in the tooltip and in the table.
 *
 * Series colours are numbered 0 to 7 (CHART_COLOURS). The stylesheet gives the legend dots and bars the same
 * numbers (.dot-0 to .dot-7), so the two stay in step.
 */
declare(strict_types=1);

/** Series colours. Each is at least 3:1 against white, the minimum for graphics. */
const CHART_COLOURS = ['#0f766e', '#2563eb', '#7c3aed', '#d97706', '#dc2626', '#0891b2', '#16a34a', '#db2777'];

function chart_colour(int $i): string
{
    return CHART_COLOURS[$i % count(CHART_COLOURS)];
}

/** A page-unique id for gradients and labels. */
function chart_uid(string $prefix = 'ch'): string
{
    static $n = 0;
    $n++;
    return $prefix . $n;
}

/** Round a maximum up to 1, 2, 5 or 10 times a power of ten, so the axis ends on a tidy number. */
function chart_nice_max(float $max): float
{
    if ($max <= 0) {
        return 1.0;
    }
    $p = 10 ** floor(log10($max));
    $f = $max / $p;
    $n = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10));
    return (float) ($n * $p);
}

/**
 * The axis maximum and the number of gridline steps. For whole numbers the steps are chosen so that every
 * gridline falls on a whole number (a count of 3 never gets a gridline at 0.75).
 *
 * @param float[] $values
 * @return array{0: float, 1: int}
 */
function chart_scale(array $values, bool $integers): array
{
    $max = $values ? max($values) : 0.0;
    $nice = chart_nice_max((float) $max);
    foreach ([4, 5, 3, 2, 1] as $steps) {
        if (!$integers || abs(fmod($nice / $steps, 1.0)) < 1e-9) {
            return [$nice, $steps];
        }
    }
    return [$nice, 1];
}

/** A number for a tooltip or table: thousands separators, decimals only when there are some. */
function chart_fmt(float $v, string $prefix = ''): string
{
    $whole = abs($v - round($v)) < 0.005;
    return $prefix . number_format($v, $whole ? 0 : 2);
}

/** A short number for an axis label: 1.2K, 3M. */
function chart_fmt_short(float $v): string
{
    $a = abs($v);
    $trim = static fn (string $s): string => strpos($s, '.') === false ? $s : rtrim(rtrim($s, '0'), '.');
    if ($a >= 1000000) {
        return $trim(number_format($v / 1000000, 1, '.', '')) . 'M';
    }
    if ($a >= 1000) {
        return $trim(number_format($v / 1000, 1, '.', '')) . 'K';
    }
    return $trim(number_format($v, $a < 10 && $v != floor($v) ? 1 : 0, '.', ''));
}

function chart_empty(string $message = 'Nothing to show yet.'): string
{
    return '<p class="chart-empty">' . e($message) . '</p>';
}

/** The hidden table that gives a screen reader the same numbers as the picture. */
function chart_table(string $caption, array $head, array $rows): string
{
    $t = '<div class="sr-only"><table><caption>' . e($caption) . '</caption><thead><tr>';
    foreach ($head as $h) {
        $t .= '<th scope="col">' . e($h) . '</th>';
    }
    $t .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $t .= '<tr>';
        foreach ($r as $i => $cell) {
            $t .= $i === 0 ? '<th scope="row">' . e($cell) . '</th>' : '<td>' . e($cell) . '</td>';
        }
        $t .= '</tr>';
    }
    return $t . '</tbody></table></div>';
}

/** A legend: coloured dots with the series name and, optionally, a value. $items = [[name, colourIndex, valueText|null], ...]. */
function chart_legend(array $items): string
{
    $l = '<ul class="legend">';
    foreach ($items as [$name, $ci, $val]) {
        $l .= '<li><span class="dot dot-' . (int) ($ci % 8) . '" aria-hidden="true"></span><span class="lg-name">' . e($name) . '</span>'
            . ($val !== null ? '<strong class="lg-val">' . e($val) . '</strong>' : '') . '</li>';
    }
    return $l . '</ul>';
}

/** Which x labels to print so they never collide: every label up to 10, then every second, third... ending on the last one. */
function chart_label_step(int $n): int
{
    return $n <= 10 ? 1 : (int) ceil($n / 7);
}

/**
 * Vertical bars, one group per label. $series = [['name' => 'Visits', 'values' => [..], 'colour' => 0..7], ...].
 * Options: summary (text for screen readers), prefix (such as "KSh "), height.
 */
function chart_bars(array $labels, array $series, array $o = []): string
{
    $n = count($labels);
    if ($n === 0 || !$series) {
        return chart_empty();
    }
    $prefix = (string) ($o['prefix'] ?? '');
    $W = 480;
    $H = (int) ($o['height'] ?? 230);
    $pl = 44;
    $pr = 8;
    $pt = 12;
    $pb = 30;
    $pw = $W - $pl - $pr;
    $ph = $H - $pt - $pb;
    $all = [];
    $ints = true;
    foreach ($series as $s) {
        foreach ($s['values'] as $v) {
            $all[] = (float) $v;
            $ints = $ints && abs((float) $v - round((float) $v)) < 1e-9;
        }
    }
    [$max, $steps] = chart_scale($all, $ints);
    $k = count($series);
    $gw = $pw / $n;
    $gap = $k > 1 ? 2.0 : 0.0;
    $bw = max(3.0, min(26.0, ($gw * 0.66 - $gap * ($k - 1)) / $k));
    $summary = (string) ($o['summary'] ?? 'Bar chart');
    $total = array_sum($all);

    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($summary) . '" focusable="false">';
    for ($s = 0; $s <= $steps; $s++) {
        $y = $pt + $ph - $ph * $s / $steps;
        $svg .= '<line class="cg" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>'
            . '<text class="ct" x="' . ($pl - 7) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . e(chart_fmt_short($max * $s / $steps)) . '</text>';
    }
    $every = chart_label_step($n);
    foreach ($labels as $i => $label) {
        $x0 = $pl + $i * $gw + ($gw - ($k * $bw + ($k - 1) * $gap)) / 2;
        foreach ($series as $j => $s) {
            $v = (float) ($s['values'][$i] ?? 0);
            if ($v <= 0) {
                continue;
            }
            $h = max(2.0, $v / $max * $ph);
            $x = $x0 + $j * ($bw + $gap);
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($pt + $ph - $h, 1) . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '" rx="3" fill="' . chart_colour((int) ($s['colour'] ?? $j)) . '">'
                . '<title>' . e($label . ': ' . ($k > 1 ? $s['name'] . ' ' : '') . chart_fmt($v, $prefix)) . '</title></rect>';
        }
        if (($n - 1 - $i) % $every === 0) {
            $svg .= '<text class="ct" x="' . round($pl + $i * $gw + $gw / 2, 1) . '" y="' . ($H - 9) . '" text-anchor="middle">' . e($label) . '</text>';
        }
    }
    if ($total <= 0) {
        $svg .= '<text class="ct ct-empty" x="' . ($W / 2) . '" y="' . round($pt + $ph / 2, 1) . '" text-anchor="middle">No activity in this period</text>';
    }
    $svg .= '</svg>';

    $legend = '';
    if ($k > 1) {
        $legend = chart_legend(array_map(static fn ($s, $j) => [$s['name'], (int) ($s['colour'] ?? $j), null], $series, array_keys($series)));
    }
    $rows = [];
    foreach ($labels as $i => $label) {
        $row = [$label];
        foreach ($series as $s) {
            $row[] = chart_fmt((float) ($s['values'][$i] ?? 0), $prefix);
        }
        $rows[] = $row;
    }
    return '<figure class="chart">' . $legend . $svg . chart_table($summary, array_merge(['Day'], array_column($series, 'name')), $rows) . '</figure>';
}

/**
 * A smooth line with a soft gradient under it. One series of values.
 * Options: name, summary, prefix, colour (0 to 7), height.
 */
function chart_area(array $labels, array $values, array $o = []): string
{
    $n = count($labels);
    if ($n < 2 || count($values) !== $n) {
        return chart_empty();
    }
    $prefix = (string) ($o['prefix'] ?? '');
    $ci = (int) ($o['colour'] ?? 0);
    $col = chart_colour($ci);
    $W = 480;
    $H = (int) ($o['height'] ?? 230);
    $pl = 44;
    $pr = 30;
    $pt = 14;
    $pb = 30;
    $pw = $W - $pl - $pr;
    $ph = $H - $pt - $pb;
    $vals = array_map('floatval', array_values($values));
    $ints = true;
    foreach ($vals as $v) {
        $ints = $ints && abs($v - round($v)) < 1e-9;
    }
    [$max, $steps] = chart_scale($vals, $ints);
    $summary = (string) ($o['summary'] ?? 'Line chart');
    $name = (string) ($o['name'] ?? 'Value');
    $id = chart_uid('ga');

    $px = static fn (int $i): float => $pl + $pw * $i / ($n - 1);
    $py = static fn (float $v): float => $pt + $ph - $ph * ($v / $max);

    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($summary) . '" focusable="false">'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $col . '" stop-opacity=".32"/><stop offset="1" stop-color="' . $col . '" stop-opacity="0"/></linearGradient></defs>';
    for ($s = 0; $s <= $steps; $s++) {
        $y = $pt + $ph - $ph * $s / $steps;
        $svg .= '<line class="cg" x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>'
            . '<text class="ct" x="' . ($pl - 7) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . e(chart_fmt_short($max * $s / $steps)) . '</text>';
    }
    // an S-shaped curve between neighbours never overshoots the data
    $d = 'M' . round($px(0), 1) . ' ' . round($py($vals[0]), 1);
    for ($i = 1; $i < $n; $i++) {
        $mx = ($px($i - 1) + $px($i)) / 2;
        $d .= ' C' . round($mx, 1) . ' ' . round($py($vals[$i - 1]), 1) . ' ' . round($mx, 1) . ' ' . round($py($vals[$i]), 1) . ' ' . round($px($i), 1) . ' ' . round($py($vals[$i]), 1);
    }
    $base = round($pt + $ph, 1);
    $svg .= '<path d="' . $d . ' L' . round($px($n - 1), 1) . ' ' . $base . ' L' . round($px(0), 1) . ' ' . $base . ' Z" fill="url(#' . $id . ')"/>'
        . '<path d="' . $d . '" fill="none" stroke="' . $col . '" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>';
    $every = chart_label_step($n);
    foreach ($labels as $i => $label) {
        $last = $i === $n - 1;
        $svg .= '<circle cx="' . round($px($i), 1) . '" cy="' . round($py($vals[$i]), 1) . '" r="' . ($last ? 5 : 3) . '" fill="#fff" stroke="' . $col . '" stroke-width="2.2"><title>' . e($label . ': ' . chart_fmt($vals[$i], $prefix)) . '</title></circle>';
        if (($n - 1 - $i) % $every === 0) {
            $svg .= '<text class="ct" x="' . round($px($i), 1) . '" y="' . ($H - 9) . '" text-anchor="middle">' . e($label) . '</text>';
        }
    }
    if (array_sum($vals) <= 0) {
        $svg .= '<text class="ct ct-empty" x="' . ($W / 2) . '" y="' . round($pt + $ph / 2, 1) . '" text-anchor="middle">No activity in this period</text>';
    }
    $svg .= '</svg>';
    $rows = [];
    foreach ($labels as $i => $label) {
        $rows[] = [$label, chart_fmt($vals[$i], $prefix)];
    }
    return '<figure class="chart">' . $svg . chart_table($summary, ['Day', $name], $rows) . '</figure>';
}

/**
 * A ring divided into slices, with a legend. $slices = [['label' => 'Cash', 'value' => 12, 'colour' => 0..7 (optional)], ...].
 * Options: summary, centre (small text under the total), prefix.
 */
function chart_donut(array $slices, array $o = []): string
{
    $prefix = (string) ($o['prefix'] ?? '');
    $slices = array_values(array_filter($slices, static fn ($s) => (float) $s['value'] > 0));
    $total = array_sum(array_map(static fn ($s) => (float) $s['value'], $slices));
    $summary = (string) ($o['summary'] ?? 'Share chart');
    $r = 42.0;
    $c = 2 * M_PI * $r;

    $svg = '<svg class="donut-svg" viewBox="0 0 120 120" role="img" aria-label="' . e($summary) . '" focusable="false">'
        . '<circle class="donut-track" cx="60" cy="60" r="' . $r . '" fill="none" stroke-width="15"/>';
    $legend = [];
    $rows = [];
    $offset = 0.0;
    foreach ($slices as $i => $s) {
        $v = (float) $s['value'];
        $ci = (int) ($s['colour'] ?? $i);
        $len = $v / $total * $c;
        $svg .= '<circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="' . chart_colour($ci) . '" stroke-width="15" stroke-dasharray="' . round(max(0.0, $len - 1.5), 2) . ' ' . round($c - max(0.0, $len - 1.5), 2)
            . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 60 60)"><title>' . e($s['label'] . ': ' . chart_fmt($v, $prefix) . ' (' . round($v / $total * 100) . '%)') . '</title></circle>';
        $offset += $len;
        $legend[] = [$s['label'], $ci, chart_fmt($v, $prefix) . ' (' . round($v / $total * 100) . '%)'];
        $rows[] = [$s['label'], chart_fmt($v, $prefix), round($v / $total * 100) . '%'];
    }
    $svg .= '<text class="donut-num" x="60" y="' . ($total > 0 ? 62 : 64) . '" text-anchor="middle">' . ($total > 0 ? e(chart_fmt_short($total)) : '0') . '</text>'
        . '<text class="donut-cap" x="60" y="77" text-anchor="middle">' . e($total > 0 ? (string) ($o['centre'] ?? 'total') : 'no data yet') . '</text></svg>';
    if ($total <= 0) {
        return '<figure class="chart chart-donut">' . $svg . '<p class="chart-empty">No activity in this period.</p></figure>';
    }
    return '<figure class="chart chart-donut">' . $svg . chart_legend($legend) . chart_table($summary, ['Item', 'Amount', 'Share'], $rows) . '</figure>';
}

/**
 * Horizontal bars with a label and value above each. $rows = [['label' => .., 'value' => .., 'note' => optional text], ...].
 * Options: summary, prefix, colour (0 to 7).
 */
function chart_hbars(array $rows, array $o = []): string
{
    $rows = array_values($rows);
    if (!$rows) {
        return chart_empty('Nothing to show yet.');
    }
    $prefix = (string) ($o['prefix'] ?? '');
    $ci = (int) ($o['colour'] ?? 0);
    $max = max(array_map(static fn ($r) => (float) $r['value'], $rows));
    $summary = (string) ($o['summary'] ?? 'Bar list');
    $h = '<figure class="chart"><ul class="hbars" aria-label="' . e($summary) . '">';
    $trs = [];
    foreach ($rows as $i => $r) {
        $v = (float) $r['value'];
        $pct = $max > 0 ? max($v > 0 ? 3.0 : 0.0, $v / $max * 100) : 0.0;
        $h .= '<li><div class="hb-top"><span class="hb-label">' . e($r['label']) . '</span><strong class="hb-val">' . e(chart_fmt($v, $prefix)) . '</strong></div>'
            . '<svg class="hb-bar" width="100%" height="9" aria-hidden="true" focusable="false"><rect width="100%" height="9" rx="4.5" class="hb-track"/>'
            . '<rect width="' . round($pct, 1) . '%" height="9" rx="4.5" fill="' . chart_colour(isset($o['cycle']) ? $i : $ci) . '"/></svg>'
            . (!empty($r['note']) ? '<span class="hb-note">' . e($r['note']) . '</span>' : '') . '</li>';
        $trs[] = [$r['label'], chart_fmt($v, $prefix)];
    }
    return $h . '</ul>' . chart_table($summary, ['Item', 'Value'], $trs) . '</figure>';
}

/** A tiny line for a KPI card, no axes. $values must have at least two numbers. */
function chart_spark(array $values, int $colour = 0): string
{
    $vals = array_map('floatval', array_values($values));
    $n = count($vals);
    if ($n < 2 || max($vals) <= 0) {
        return '';
    }
    $max = max($vals);
    $min = min($vals);
    $span = max($max - $min, 1e-9);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round(100 * $i / ($n - 1), 1) . ',' . round(26 - 22 * (($v - $min) / $span), 1);
    }
    return '<svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false"><polyline points="' . implode(' ', $pts)
        . '" fill="none" stroke="' . chart_colour($colour) . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>';
}
