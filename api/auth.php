<?php
// 星港导航 · 认证接口（账号唯一 + 账号锁定 + IP 级限速 + CSRF/同源校验 + 密码信封加密 + 安全审计）
require __DIR__ . '/config.php';

// GET ?action=status —— 查询当前登录状态，并下发会话绑定 CSRF 令牌
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    start_session();
    json_out([
        'ok'     => true,
        'logged' => !empty($_SESSION['admin']),
        'user'   => $_SESSION['admin'] ?? null,
        'role'   => $_SESSION['role'] ?? null,
        'csrf'   => csrf_token(),
    ]);
}

// GET ?action=pubkey —— 下发会话级 RSA 公钥（前端用于加密登录密码）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'pubkey') {
    start_session();
    $pub = rsa_public_key();
    if ($pub === '') {
        json_out(['ok' => false, 'error' => 'crypto-unavailable', 'msg' => '加密组件不可用，请联系管理员'], 500);
    }
    json_out(['ok' => true, 'pubkey' => $pub]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = read_json_body();
    $action = (string)($body['action'] ?? '');

    if ($action === 'logout') {
        start_session();
        check_csrf();
        $who = (string)($_SESSION['admin'] ?? '');
        $_SESSION = [];
        session_regenerate_id(true); // 清空数据并更换会话 ID，防登出后会话复用
        $fresh = csrf_token();       // 新匿名会话立即下发新令牌，便于前端再次登录
        audit_log('logout', $who);
        json_out(['ok' => true, 'csrf' => $fresh]);
    }

    if ($action === 'login') {
        start_session();
        check_csrf(); // 登录请求同样校验 CSRF（前端启动时先 GET status 获取令牌），防跨站登录伪造
        $u   = trim((string)($body['user'] ?? ''));
        $enc = (string)($body['pass_enc'] ?? '');
        $ip  = client_ip();

        // ---- IP 级限速：锁定期间直接拒绝，防横向撞库/账号枚举 ----
        [, $lockedUntil] = ip_guard_state($ip);
        $ipLockedRemaining = $lockedUntil - time();
        if ($ipLockedRemaining > 0) {
            audit_log('ip_blocked', '', 'reject during ip lock');
            json_out([
                'ok'             => false,
                'error'          => 'locked',
                'ip_locked'      => true,
                'locked_seconds' => $ipLockedRemaining,
            ], 429);
        }

        // ---- 密码信封解密：只接受会话公钥加密的密文，缺失/无效按登录失败处理（不泄露细节）----
        $p = rsa_decrypt_password($enc);
        if ($p === null) {
            ip_register_fail($ip);
            usleep(300000);
            audit_log('login_fail', '', 'user=' . mb_substr($u, 0, 32) . ' reason=no-cipher');
            json_out(['ok' => false, 'error' => 'bad-credentials'], 401);
        }

        // 账号唯一：按用户名精确匹配（数据库 UNIQUE 索引保证唯一性）
        $st = db()->prepare('SELECT id, username, pass_hash, failed_count, locked_until, role FROM nav_admin WHERE username = ?');
        $st->execute([$u === '' ? "\0absent" : $u]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        // 账号不存在：统一模糊提示（防用户名枚举），固定延迟，并计入 IP 失败
        if (!$row) {
            ip_register_fail($ip);
            usleep(300000);
            audit_log('login_fail', '', 'user=' . mb_substr($u, 0, 32) . ' reason=no-user');
            json_out(['ok' => false, 'error' => 'bad-credentials'], 401);
        }

        // 锁定检查（数据库级，跨会话/跨设备生效）
        $lockedRemaining = (int)$row['locked_until'] - time();
        if ($lockedRemaining > 0) {
            audit_log('account_blocked', $row['username'], 'reject during account lock');
            json_out([
                'ok'             => false,
                'error'          => 'locked',
                'locked_seconds' => $lockedRemaining,
            ], 429);
        }

        if ($u !== '' && $p !== '' && password_verify($p, $row['pass_hash'])) {
            // 登录成功：清零失败计数、清除 IP 限速记录、写入角色
            db()->prepare('UPDATE nav_admin SET failed_count = 0, locked_until = 0 WHERE id = ?')
                ->execute([$row['id']]);
            ip_clear($ip);
            rsa_drop_keypair(); // 销毁会话临时密钥对：密文不可重放
            session_regenerate_id(true); // 防会话固定攻击（保留会话数据，含 CSRF 令牌）
            $_SESSION['fails']    = 0;
            $_SESSION['admin']    = $row['username'];
            $_SESSION['role']    = $row['role'] ?: 'user';
            $_SESSION['last_active'] = time();
            audit_log('login_ok', $row['username']);
            json_out(['ok' => true, 'user' => $row['username'], 'role' => $_SESSION['role'], 'csrf' => csrf_token()]);
        }

        // 登录失败：账号级计数 + IP 级计数；连续 LOCK_THRESHOLD 次锁定 LOCK_SECONDS 秒
        $fails = (int)$row['failed_count'] + 1;
        if ($fails >= LOCK_THRESHOLD) {
            db()->prepare('UPDATE nav_admin SET failed_count = 0, locked_until = ? WHERE id = ?')
                ->execute([time() + LOCK_SECONDS, $row['id']]);
            ip_register_fail($ip);
            audit_log('account_locked', $row['username'], 'fails hit threshold');
            usleep(300000);
            json_out([
                'ok'             => false,
                'error'          => 'locked',
                'locked_seconds' => LOCK_SECONDS,
            ], 429);
        }

        db()->prepare('UPDATE nav_admin SET failed_count = ? WHERE id = ?')
            ->execute([$fails, $row['id']]);
        ip_register_fail($ip);
        audit_log('login_fail', '', 'user=' . mb_substr($u, 0, 32) . ' reason=bad-pass');
        usleep(300000); // 失败固定 300ms 延迟，抬高暴力破解成本
        json_out([
            'ok'        => false,
            'error'     => 'bad-credentials',
            'remaining' => LOCK_THRESHOLD - $fails, // 剩余尝试次数
        ], 401);
    }
}

json_out(['ok' => false, 'error' => 'bad-request'], 400);
