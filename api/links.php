<?php
// 星港导航 · 链接数据接口（角色权限版）
// GET  —— 访客/管理员：原始首页；普通用户：自己的导航页（首次自动复制原始首页）
// POST —— 管理员：保存原始首页；普通用户：保存自己的导航页（服务端强制，无法越权）
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $u     = current_user();
    $owner = page_owner_for($u);
    $pdo   = db();

    // 普通用户首次访问：自动从原始首页复制一份个人页
    if ($owner !== '') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM nav_links WHERE owner = ?');
        $st->execute([$owner]);
        if ((int)$st->fetchColumn() === 0) {
            if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $owner)) {
                json_out(['ok' => false, 'error' => 'bad-owner'], 400);
            }
            $pdo->prepare("INSERT INTO nav_links (owner, id, name, url, domain, grp, c1, c2, pos)
                           SELECT ?, id, name, url, domain, grp, c1, c2, pos FROM nav_links WHERE owner = ''")
                ->execute([$owner]);
        }
    }

    $st = $pdo->prepare('SELECT id, name, url, domain, grp, c1, c2 FROM nav_links WHERE owner = ? ORDER BY pos, id');
    $st->execute([$owner]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
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
    json_out([
        'ok'    => true,
        'links' => $links,
        'user'  => $u['username'] ?? null,
        'role'  => $u['role'] ?? null,
        'page'  => $owner === '' ? 'original' : 'personal',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u     = require_auth();   // 未登录 401
    check_csrf();
    $owner = page_owner_for($u); // 服务端决定可写归属：管理员→原始首页；普通用户→本人页面
    $body  = read_json_body();
    $links = $body['links'] ?? null;
    if (!is_array($links) || count($links) > 200) {
        json_out(['ok' => false, 'error' => 'bad-data'], 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 只清空本人/本页归属的数据，绝不动其他用户的页面或原始首页
        $pdo->prepare('DELETE FROM nav_links WHERE owner = ?')->execute([$owner]);
        $st  = $pdo->prepare('INSERT INTO nav_links (id, name, url, domain, grp, c1, c2, pos, owner) VALUES (?,?,?,?,?,?,?,?,?)');
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
            $st->execute([$id, $name, $url, $domain, $grp, $c1, $c2, $pos++, $owner]);
        }
        $pdo->commit();
        json_out(['ok' => true, 'saved' => $pos, 'page' => $owner === '' ? 'original' : 'personal']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'db-error'], 500);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);