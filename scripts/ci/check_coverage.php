<?php

declare(strict_types=1);

/**
 * check_coverage.php — Per-class coverage gate for touched source files.
 *
 * Reads a list of changed PHP source files from stdin (one per line) or
 * from the first CLI argument as a comma-separated list, then runs
 * PHPUnit coverage for the corresponding test files and asserts that
 * every touched class has ≥ MIN_COVERAGE method coverage.
 *
 * Usage (inside Docker container):
 *   git diff --name-only HEAD | php scripts/ci/check_coverage.php
 *   php scripts/ci/check_coverage.php src/Controllers/RiskController.php,src/Models/Risk.php
 *
 * Exit codes:
 *   0 — all covered at threshold, or no testable source files in diff
 *   1 — one or more classes below threshold
 *   2 — PHPUnit or parse error
 *
 * Environment:
 *   COVERAGE_MIN_METHODS   int   Minimum method coverage % (default 80)
 *   COVERAGE_MIN_LINES     int   Minimum line coverage %   (default 80)
 *   SKIP_COVERAGE_CHECK    1     Skip this check entirely
 */

const DEFAULT_MIN = 80;

// ===========================
// Config
// ===========================

if (getenv('SKIP_COVERAGE_CHECK') === '1') {
    echo "[coverage] SKIP_COVERAGE_CHECK=1 — skipping.\n";
    exit(0);
}

$minMethods = (int) (getenv('COVERAGE_MIN_METHODS') ?: DEFAULT_MIN);
$minLines   = (int) (getenv('COVERAGE_MIN_LINES')   ?: DEFAULT_MIN);

$repoRoot = dirname(__DIR__, 2); // stratflow/

// ===========================
// Source-to-test mapping (mirrors check_test_touches.py)
// ===========================

const SKIP_DIRS = [
    'src/Config',
    'src/Services/Prompts',
];

const MAPPING_RULES = [
    ['src/Controllers/', 'tests/Unit/Controllers/', 'Test'],
    ['src/Models/',      'tests/Unit/Models/',      'Test'],
    ['src/Services/',    'tests/Unit/Services/',    'Test'],
    ['src/Middleware/',  'tests/Unit/Middleware/',  'Test'],
    ['src/Security/',   'tests/Unit/Security/',    'Test'],
    ['src/Core/',       'tests/Unit/Core/',        'Test'],
    ['src/',            'tests/Unit/',             'Test'],
];

function sourceToTest(string $src): ?string
{
    $p = str_replace('\\', '/', $src);

    if (!str_ends_with($p, '.php')) {
        return null;
    }

    foreach (SKIP_DIRS as $skip) {
        if (str_starts_with($p, $skip . '/')) {
            return null;
        }
    }

    foreach (MAPPING_RULES as [$srcPrefix, $testPrefix, $suffix]) {
        if (str_starts_with($p, $srcPrefix)) {
            $remainder = substr($p, strlen($srcPrefix));
            return $testPrefix . substr($remainder, 0, -4) . $suffix . '.php';
        }
    }

    return null;
}

// ===========================
// Collect changed source files
// ===========================

$inputFiles = [];

if (isset($argv[1]) && $argv[1] !== '') {
    $inputFiles = explode(',', $argv[1]);
} else {
    $stdin = file_get_contents('php://stdin');
    $inputFiles = array_filter(array_map('trim', explode("\n", $stdin)));
}

// Normalise to relative paths from repo root
$inputFiles = array_map(
    fn($f) => ltrim(str_replace('\\', '/', $f), '/'),
    $inputFiles
);

// ===========================
// Find pairs: source class → test file that exists
// ===========================

$pairs = [];

foreach ($inputFiles as $srcFile) {
    $testPath = sourceToTest($srcFile);
    if ($testPath === null) {
        continue;
    }

    $absTest = $repoRoot . '/' . $testPath;
    if (!file_exists($absTest)) {
        // Test file doesn't exist yet — check_test_touches handles this separately
        continue;
    }

    $pairs[] = ['src' => $srcFile, 'test' => $testPath, 'absTest' => $absTest];
}

if (empty($pairs)) {
    echo "[coverage] No testable source files with matching test files — nothing to check.\n";
    exit(0);
}

// ===========================
// Run PHPUnit coverage (clover format)
// ===========================

$cloverFile = sys_get_temp_dir() . '/phpunit_coverage_' . getmypid() . '.xml';

// For each test file, also include ALL test files in the same directory.
// This ensures coverage from sister test files (e.g. AuthPrincipalTest.php
// alongside AuthTest.php) is included when measuring a class's coverage.
$testDirs = [];
foreach ($pairs as ['absTest' => $absTest]) {
    $testDirs[dirname($absTest)] = true;
}
$testDirArgs = implode(' ', array_map(
    fn($d) => escapeshellarg($d),
    array_keys($testDirs)
));

$dirCount = count($testDirs);
$cmd = sprintf(
    'cd %s && XDEBUG_MODE=coverage php vendor/phpunit/phpunit/phpunit'
    . ' -c tests/phpunit.xml'
    . ' --coverage-clover %s'
    . ' %s'
    . ' 2>/dev/null',
    escapeshellarg($repoRoot),
    escapeshellarg($cloverFile),
    $testDirArgs
);

echo "[coverage] Running PHPUnit coverage for " . $dirCount . " test director" . ($dirCount === 1 ? 'y' : 'ies') . "...\n";
exec($cmd, $output, $exitCode);

if (!file_exists($cloverFile)) {
    echo "[coverage] ERROR: Coverage report not generated (PHPUnit exit code: $exitCode)\n";
    echo implode("\n", $output) . "\n";
    exit(2);
}

// ===========================
// Parse clover XML
// ===========================

$xml = simplexml_load_file($cloverFile);
unlink($cloverFile);

if ($xml === false) {
    echo "[coverage] ERROR: Could not parse clover XML.\n";
    exit(2);
}

/**
 * Build a map of: normalised-source-path → ['methods' => %, 'lines' => %]
 *
 * Method coverage is counted from <line type="method"> elements (more accurate
 * than the <metrics coveredmethods> attribute, which Xdebug can under-count for
 * static and private methods).
 *
 * Line coverage uses the <metrics> statements/coveredstatements which is reliable.
 */
$classCoverage = [];

foreach ($xml->xpath('//file') ?? [] as $fileNode) {
    $filePath = (string) $fileNode['name'];

    // Method coverage: count <line type="method"> elements; covered = count > 0
    $methodLines    = $fileNode->xpath('.//line[@type="method"]') ?? [];
    $totalMethods   = count($methodLines);
    $coveredMethods = 0;
    foreach ($methodLines as $mLine) {
        if ((int) $mLine['count'] > 0) {
            $coveredMethods++;
        }
    }

    // Line/statement coverage from the file-level <metrics> element (last child <metrics>)
    // Try file-level metrics first, then class-level
    $metricsNodes = $fileNode->xpath('./metrics | ./class/metrics') ?? [];
    $statements    = 0;
    $covStatements = 0;
    foreach ($metricsNodes as $m) {
        if (isset($m['statements'])) {
            $statements    = (int) $m['statements'];
            $covStatements = (int) $m['coveredstatements'];
            break;
        }
    }

    $methodPct = $totalMethods > 0 ? (int) round($coveredMethods / $totalMethods * 100) : 100;
    $linePct   = $statements   > 0 ? (int) round($covStatements   / $statements   * 100) : 100;

    // Normalise to relative path from repo root
    $rel = str_replace($repoRoot . '/', '', str_replace('\\', '/', $filePath));
    $classCoverage[$rel] = ['methods' => $methodPct, 'lines' => $linePct];
}

// ===========================
// Check each touched source class
// ===========================

$failures = [];

foreach ($pairs as ['src' => $src]) {
    if (!isset($classCoverage[$src])) {
        // Class not in clover report — may be 0% or unmapped
        $failures[] = sprintf(
            "  ❌ %s — not found in coverage report (0%% assumed); need ≥%d%%",
            $src,
            $minMethods
        );
        continue;
    }

    $mc = $classCoverage[$src]['methods'];
    $lc = $classCoverage[$src]['lines'];

    $methodOk = $mc >= $minMethods;
    $lineOk   = $lc >= $minLines;

    if ($methodOk && $lineOk) {
        echo sprintf("  ✅ %s — methods %d%%, lines %d%%\n", $src, $mc, $lc);
    } else {
        $failures[] = sprintf(
            "  ❌ %s — methods %d%% (need %d%%), lines %d%% (need %d%%)",
            $src,
            $mc,
            $minMethods,
            $lc,
            $minLines
        );
    }
}

if (empty($failures)) {
    echo "\n[coverage] All touched classes meet the ≥{$minMethods}% coverage threshold. ✅\n";
    exit(0);
}

echo "\n[coverage] Coverage gate FAILED — " . count($failures) . " class(es) below threshold:\n";
foreach ($failures as $msg) {
    echo $msg . "\n";
}
echo "\nOptions:\n";
echo "  1. Add or improve unit tests until coverage is ≥{$minMethods}%.\n";
echo "  2. Set SKIP_COVERAGE_CHECK=1 to bypass (use only for non-logic changes).\n";
exit(1);
