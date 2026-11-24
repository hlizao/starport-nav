<?php
// 星港导航 · 书签接口（仅登录可读写，按当前用户隔离；书签为各用户私人收藏）
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $u = require_auth();
    $st = db()->prepare('SELECT id, name, url, folder FROM nav_bookmarks WHERE owner = ? ORDER BY pos, id');
    $st->execute([$u['username']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'bookmarks' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = require_auth();
    check_csrf();
    $body = read_json_body();
    $bms = $body['bookmarks'] ?? null;
    if (!is_array($bms) || count($bms) > 500) {
        json_out(['ok' => false, 'error' => 'bad-data'], 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 只清空本人书签
        $pdo->prepare('DELETE FROM nav_bookmarks WHERE owner = ?')->execute([$u['username']]);
        $st  = $pdo->prepare('INSERT INTO nav_bookmarks (id, name, url, folder, pos, owner) VALUES (?,?,?,?,?,?)');
        $pos = 0;
        foreach ($bms as $b) {
            if (!is_array($b)) continue;
            $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 50);
            $url  = trim((string)($b['url'] ?? ''));
            if ($name === '' || !preg_match('#^https?://#i', $url) || strlen($url) > 500) continue;
            $folderRaw = trim((string)($b['folder'] ?? ''));
            if ($folderRaw === '') $folderRaw = '默认';
            $folder = mb_substr($folderRaw, 0, 30);
            $idRaw = (string)($b['id'] ?? '');
            $id = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $idRaw)
                ? $idRaw
                : 'b' . substr(md5(uniqid('', true)), 0, 10);
            $st->execute([$id, $name, $url, $folder, $pos++, $u['username']]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'saved' => $pos]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'db-error'], 500);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);