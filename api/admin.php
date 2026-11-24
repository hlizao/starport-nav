<?php
// 星港导航 · 账号管理接口（仅登录管理员可用）
require __DIR__ . '/config.php';

// ---- GET：账号列表（含锁定状态） ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_auth();
    $rows = db()->query('SELECT id, username, failed_count, locked_until, created_at FROM nav_admin ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $list = array_map(function ($r) {
        $locked = (int)$r['locked_until'] > time();
        return [
            'id'            => (int)$r['id'],
            'username'      => $r['username'],
            'locked'        => $locked,
            'locked_seconds' => $locked ? ((int)$r['locked_until'] - time()) : 0,
            'failed_count'  => (int)$r['failed_count'],
            'created_at'    => $r['created_at'],
        ];
    }, $rows);
    json_out(['ok' => true, 'accounts' => $list, 'self' => $_SESSION['admin']]);
}

// ---- POST：创建 / 改密 / 解锁 / 删除 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth();
    check_csrf();
    $body   = read_json_body();
    $action = (string)($body['action'] ?? '');

    // 创建账号：用户名唯一 + 密码复杂度强校验
    if ($action === 'create') {
        $u = trim((string)($body['username'] ?? ''));
        $p = (string)($body['pass'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $u)) {
            json_out(['ok' => false, 'error' => 'bad-username',
                      'msg' => '用户名须为 3~30 位字母/数字/_/-'], 400);
        }
        $policy = password_policy_errors($p);
        if ($policy) {
            json_out(['ok' => false, 'error' => 'weak-password', 'msg' => implode('；', $policy)], 400);
        }
        $dup = db()->prepare('SELECT id FROM nav_admin WHERE username = ?');
        $dup->execute([$u]);
        if ($dup->fetch()) {
            json_out(['ok' => false, 'error' => 'duplicate-username', 'msg' => '该用户名已存在'], 409);
        }
        db()->prepare('INSERT INTO nav_admin (username, pass_hash) VALUES (?, ?)')
            ->execute([$u, password_hash($p, PASSWORD_BCRYPT)]);
        json_out(['ok' => true, 'created' => $u]);
    }

    // 修改密码：复杂度强校验；改密成功顺带解锁
    if ($action === 'change_pass') {
        $id = (int)($body['id'] ?? 0);
        $p  = (string)($body['pass'] ?? '');
        $policy = password_policy_errors($p);
        if ($policy) {
            json_out(['ok' => false, 'error' => 'weak-password', 'msg' => implode('；', $policy)], 400);
        }
        $st = db()->prepare('UPDATE nav_admin SET pass_hash = ?, failed_count = 0, locked_until = 0 WHERE id = ?');
        $st->execute([password_hash($p, PASSWORD_BCRYPT), $id]);
        json_out(['ok' => true, 'changed' => $st->rowCount() > 0]);
    }

    // 管理员解锁：清零失败计数与锁定
    if ($action === 'unlock') {
        $id = (int)($body['id'] ?? 0);
        $st = db()->prepare('UPDATE nav_admin SET failed_count = 0, locked_until = 0 WHERE id = ?');
        $st->execute([$id]);
        json_out(['ok' => true, 'unlocked' => $st->rowCount() > 0]);
    }

    // 删除账号：不允许删除当前登录账号、不允许删除最后一个账号
    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        $row = db()->prepare('SELECT username FROM nav_admin WHERE id = ?');
        $row->execute([$id]);
        $target = $row->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            json_out(['ok' => false, 'error' => 'not-found'], 404);
        }
        if ($target['username'] === $_SESSION['admin']) {
            json_out(['ok' => false, 'error' => 'self-delete', 'msg' => '不能删除当前登录的账号'], 400);
        }
        $count = (int)db()->query('SELECT COUNT(*) FROM nav_admin')->fetchColumn();
        if ($count <= 1) {
            json_out(['ok' => false, 'error' => 'last-account', 'msg' => '至少保留一个账号'], 400);
        }
        db()->prepare('DELETE FROM nav_admin WHERE id = ?')->execute([$id]);
        json_out(['ok' => true, 'deleted' => $target['username']]);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);