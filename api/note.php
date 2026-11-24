<?php
// 星港导航 · 便签接口（仅登录可读写）
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    $row = db()->query('SELECT content FROM nav_note WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'note' => (string)($row['content'] ?? '')]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    check_csrf();
    $body = read_json_body();
    $note = mb_substr((string)($body['note'] ?? ''), 0, 20000);
    $st = db()->prepare('INSERT INTO nav_note (id, content) VALUES (1, ?) ON DUPLICATE KEY UPDATE content = ?');
    $st->execute([$note, $note]);
    json_out(['ok' => true, 'saved' => mb_strlen($note)]);
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);