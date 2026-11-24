<?php
// 星港导航 · 认证接口（账号唯一性 + 登录失败处理 + 锁定）
require __DIR__ . '/config.php';

// GET ?action=status —— 查询当前登录状态
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    start_session();
    json_out([
        'ok'     => true,
        'logged' => !empty($_SESSION['admin']),
        'user'   => $_SESSION['admin'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = read_json_body();
    $action = (string)($body['action'] ?? '');

    if ($action === 'logout') {
        start_session();
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);
    }

    if ($action === 'login') {
        $u = trim((string)($body['user'] ?? ''));
        $p = (string)($body['pass'] ?? '');
        start_session();

        // 账号唯一：按用户名精确匹配（数据库 UNIQUE 索引保证唯一性）
        $st = db()->prepare('SELECT id, username, pass_hash, failed_count, locked_until FROM nav_admin WHERE username = ?');
        $st->execute([$u === '' ? "\0absent" : $u]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        // 账号不存在：统一模糊提示（防用户名枚举），固定延迟
        if (!$row) {
            usleep(300000);
            json_out(['ok' => false, 'error' => 'bad-credentials'], 401);
        }

        // 锁定检查（数据库级，跨会话/跨设备生效）
        $lockedRemaining = (int)$row['locked_until'] - time();
        if ($lockedRemaining > 0) {
            json_out([
                'ok'            => false,
                'error'         => 'locked',
                'locked_seconds' => $lockedRemaining,
            ], 429);
        }

        if ($u !== '' && $p !== '' && password_verify($p, $row['pass_hash'])) {
            // 登录成功：清零失败计数
            db()->prepare('UPDATE nav_admin SET failed_count = 0, locked_until = 0 WHERE id = ?')
                ->execute([$row['id']]);
            $_SESSION['fails'] = 0;
            session_regenerate_id(true); // 防会话固定攻击
            $_SESSION['admin'] = $row['username'];
            json_out(['ok' => true, 'user' => $row['username']]);
        }

        // 登录失败：数据库级计数；连续 LOCK_THRESHOLD 次锁定 LOCK_SECONDS 秒
        $fails = (int)$row['failed_count'] + 1;
        if ($fails >= LOCK_THRESHOLD) {
            db()->prepare('UPDATE nav_admin SET failed_count = 0, locked_until = ? WHERE id = ?')
                ->execute([time() + LOCK_SECONDS, $row['id']]);
            usleep(300000);
            json_out([
                'ok'             => false,
                'error'          => 'locked',
                'locked_seconds' => LOCK_SECONDS,
            ], 429);
        }

        db()->prepare('UPDATE nav_admin SET failed_count = ? WHERE id = ?')
            ->execute([$fails, $row['id']]);
        usleep(300000); // 失败固定 300ms 延迟，抬高暴力破解成本
        json_out([
            'ok'        => false,
            'error'     => 'bad-credentials',
            'remaining' => LOCK_THRESHOLD - $fails, // 剩余尝试次数
        ], 401);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);