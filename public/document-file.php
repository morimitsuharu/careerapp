<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(404); exit; }
$statement = db()->prepare('SELECT source_file, source_name FROM documents WHERE id = ?');
$statement->execute([$id]);
$row = $statement->fetch();
if (!$row || !preg_match('/\A[a-f0-9]{64}\.docx\z/D', $row['source_file'])) { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/storage/document-sources/' . $row['source_file'];
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="original.docx"; filename*=UTF-8\'\'' . rawurlencode($row['source_name']));
header('Content-Length: ' . filesize($path));
session_write_close();
readfile($path);
