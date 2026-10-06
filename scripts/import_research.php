<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/research.php';
$report = json_decode(file_get_contents(dirname(__DIR__) . '/database/research-2026-10-06.json'), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(importResearch($report), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
