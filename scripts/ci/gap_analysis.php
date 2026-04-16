<?php
$xml = simplexml_load_file("/tmp/cov.xml");
$results = [];
foreach ($xml->xpath("//file") as $f) {
    $path = (string)$f["name"];
    if (!str_contains($path, "/src/")) continue;
    $rel = preg_replace('#.*/src/#', 'src/', $path);
    $ms = $f->xpath("./metrics");
    if (empty($ms)) $ms = $f->xpath("./class/metrics");
    if (empty($ms)) continue;
    $metric = $ms[0];
    $total = (int)$metric["statements"];
    $cov = (int)$metric["coveredstatements"];
    if ($total < 10) continue;
    $pct = $total > 0 ? round($cov / $total * 100, 1) : 100.0;
    $gap = $total - $cov;
    if ($pct < 80 && $gap > 20) {
        $results[] = [$pct, $gap, $rel, $total, $cov];
    }
}
usort($results, function ($a, $b) { return $b[1] - $a[1]; });
foreach (array_slice($results, 0, 25) as $r) {
    printf("%-65s %5.1f%% (%d/%d, gap %d)\n", $r[2], $r[0], $r[4], $r[3], $r[1]);
}
