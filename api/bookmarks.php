<?php
// 星港导航 · 书签接口（仅登录可读写，书签为管理员私人收藏）
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    $rows = db()->query('SELECT id, name, url, folder FROM nav_bookmarks ORDER BY pos, id')->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'bookmarks' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    check_csrf();
    $body = read_json_body();
    $bms = $body['bookmarks'] ?? null;
    if (!is_array($bms) || count($bms) > 500) {
        json_out(['ok' => false, 'error' => 'bad-data'], 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM nav_bookmarks');
        $st  = $pdo->prepare('INSERT INTO nav_bookmarks (id, name, url, folder, pos) VALUES (?,?,?,?,?)');
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
            $st->execute([$id, $name, $url, $folder, $pos++]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'saved' => $pos]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'db-error'], 500);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);