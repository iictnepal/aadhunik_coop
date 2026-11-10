<?php
/** Counts a download, then sends the visitor to the file */
require_once __DIR__ . '/config/config.php';
$st = $pdo->prepare("SELECT id, file_path FROM downloads WHERE id=? AND status='active'");
$st->execute([(int)($_GET['id'] ?? 0)]);
$d = $st->fetch();
if (!$d) {
    http_response_code(404);
    exit('File not found.');
}
$pdo->prepare("UPDATE downloads SET download_count=download_count+1 WHERE id=?")->execute([$d['id']]);
header('Location: ' . media_url($d['file_path']));
