<?php
if (($_GET['key'] ?? '') !== 'sf2026') { http_response_code(403); exit; }
header('Content-Type: text/plain');
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "file_uploads: " . ini_get('file_uploads') . "\n";
echo "php.ini loaded: " . php_ini_loaded_file() . "\n";
echo "Scan dir: " . (php_ini_scanned_files() ?: 'none') . "\n";
