<?php
// Temporary debug endpoint - remove after fixing
header('Content-Type: text/plain');
echo "DocRoot: " . __DIR__ . "\n";
echo "CWD: " . getcwd() . "\n\n";

$cssPath = __DIR__ . '/assets/css/app.css';
echo "CSS path: $cssPath\n";
echo "CSS exists: " . (file_exists($cssPath) ? 'YES (' . filesize($cssPath) . ' bytes)' : 'NO') . "\n\n";

echo "Files in " . __DIR__ . "/assets/:\n";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/assets/', RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $file) {
    echo "  " . $file->getPathname() . " (" . $file->getSize() . ")\n";
}
