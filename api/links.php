<?php
// 星港导航 · 链接数据接口
// GET  —— 公开读取全部链接
// POST —— 需登录：整份替换保存（JSON: {"links":[{name,url,group,c1,c2}...]}）
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db()->query('SELECT id, name, url, domain, grp, c1, c2 FROM nav_links ORDER BY pos, id')->fetchAll(PDO::FETCH_ASSOC);
    $links = array_map(function ($r) {
        return [
            'id'     => $r['id'],
            'name'   => $r['name'],
            'url'    => $r['url'],
            'domain' => $r['domain'],
            'group'  => $r['grp'],
            'c1'     => $r['c1'],
            'c2'     => $r['c2'],
        ];
    }, $rows);
    json_out(['ok' => true, 'links' => $links]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    check_csrf();
    $body  = read_json_body();
    $links = $body['links'] ?? null;
    if (!is_array($links) || count($links) > 200) {
        json_out(['ok' => false, 'error' => 'bad-data'], 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM nav_links');
        $st  = $pdo->prepare('INSERT INTO nav_links (id, name, url, domain, grp, c1, c2, pos) VALUES (?,?,?,?,?,?,?,?)');
        $pos = 0;
        foreach ($links as $l) {
            if (!is_array($l)) continue;
            $name = mb_substr(trim((string)($l['name'] ?? '')), 0, 50);
            $url  = trim((string)($l['url'] ?? ''));
            if ($name === '' || !preg_match('#^https?://#i', $url) || strlen($url) > 500) continue;
            $grpRaw = (string)($l['grp'] ?? $l['group'] ?? 'office');
            $grp    = $grpRaw === 'life' ? 'life' : 'office';
            $domain = (string)preg_replace('#^https?://#i', '', $url);
            $domain = mb_substr((string)explode('/', $domain)[0], 0, 200);
            $c1 = (string)($l['c1'] ?? '');
            $c2 = (string)($l['c2'] ?? '');
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $c1)) $c1 = '#3B82F6';
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $c2)) $c2 = '#818CF8';
            $idRaw = (string)($l['id'] ?? '');
            $id = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $idRaw)
                ? $idRaw
                : 'l' . substr(md5(uniqid('', true)), 0, 10);
            $st->execute([$id, $name, $url, $domain, $grp, $c1, $c2, $pos++]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'saved' => $pos]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'db-error'], 500);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);