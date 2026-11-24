<?php
// 星港导航 · 认证接口
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
        // 服务端限速：同一会话 60 秒内失败 5 次即锁定
        if (($_SESSION['lock'] ?? 0) > time()) {
            json_out(['ok' => false, 'error' => 'locked'], 429);
        }

        if ($u !== '' && $p !== '') {
            $row = db()->query('SELECT username, pass_hash FROM nav_admin WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals($row['username'], $u) && password_verify($p, $row['pass_hash'])) {
                $_SESSION['fails'] = 0;
                session_regenerate_id(true);
                $_SESSION['admin'] = $row['username'];
                json_out(['ok' => true, 'user' => $row['username']]);
            }
        }

        $_SESSION['fails'] = (int)($_SESSION['fails'] ?? 0) + 1;
        usleep(300000); // 固定 300ms 延迟，抬高暴力破解成本
        if ($_SESSION['fails'] >= 5) {
            $_SESSION['lock']  = time() + 60;
            $_SESSION['fails'] = 0;
        }
        json_out(['ok' => false, 'error' => 'bad-credentials'], 401);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);