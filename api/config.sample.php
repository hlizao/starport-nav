<?php
// 星港导航 · 配置模板：复制本文件为 config.php 并填入数据库连接信息与站点域名（config.php 已在 .gitignore 排除）
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'your_database_name';
const DB_USER = 'your_database_user';
const DB_PASS = 'your_database_password';

/* ============ 安全参数 ============ */
const SESSION_IDLE_TIMEOUT = 1800;   // 会话空闲超时（秒）：30 分钟无操作强制登出
const IP_FAIL_WINDOW       = 900;    // IP 级失败统计窗口（秒）：15 分钟
const IP_FAIL_THRESHOLD    = 20;     // 窗口内最多允许的登录失败次数（含账号不存在的尝试）
const IP_LOCK_SECONDS      = 900;    // 触发阈值后 IP 被锁定时长（秒）：15 分钟
const ALLOWED_ORIGIN       = 'https://your-domain.example'; // 同源校验基准：改成实际站点域名（写操作强制比对）

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

function client_ip(): string {
    // 仅信任 REMOTE_ADDR：共享主机未配置可信代理头，X-Forwarded-For 等可被伪造，不作为风控依据
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

/* ============ 安全审计 ============ */
/* 关键安全事件落库（nav_audit）：登录成功/失败、账号/IP 锁定、账号增删改密、登出 */
function audit_log(string $action, string $actor = '', string $detail = ''): void {
    try {
        db()->prepare('INSERT INTO nav_audit (ts, actor, ip, action, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([time(), mb_substr($actor, 0, 64), client_ip(), mb_substr($action, 0, 32), mb_substr($detail, 0, 255)]);
        // 低频清理：约 1% 概率顺带删除 90 天前的旧审计记录，防止表无限膨胀
        if (random_int(1, 100) === 1) {
            db()->prepare('DELETE FROM nav_audit WHERE ts < ?')->execute([time() - 90 * 86400]);
        }
    } catch (Throwable $e) {
        // 审计写入失败不影响主流程
    }
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_start();
    // 会话空闲超时：距上次活动超过阈值则清空数据并更换会话 ID（等效强制登出，防会话劫持）
    if (!empty($_SESSION['last_active']) && (time() - (int)$_SESSION['last_active']) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_active'] = time();
}

/* ============ CSRF 令牌（会话绑定） ============ */
/* 首次访问（GET status）时生成；写操作必须回传且与会话内令牌 hash_equals 匹配 */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============ 权限模型 ============ */
/* 角色：admin=管理员（可编辑原始首页 + 账号管理）；user=普通用户（仅编辑自己的导航页）。
   数据归属：nav_links.owner 为 '' 表示原始首页（任何人可见，仅管理员可改）；
   为用户名表示该用户的个人导航页（仅本人可见可改，首次登录自动从原始首页复制）。 */
function current_user(): ?array {
    start_session();
    if (empty($_SESSION['admin'])) return null;
    return [
        'username' => (string)$_SESSION['admin'],
        'role'     => (string)($_SESSION['role'] ?? 'user'),
    ];
}

/* 当前会话可操作的页面归属：管理员/访客 → 原始首页（''）；普通用户 → 本人页面 */
function page_owner_for(?array $u): string {
    if ($u !== null && $u['role'] === 'user') return $u['username'];
    return '';
}

function require_auth(): array {
    $u = current_user();
    if ($u === null) {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    return $u;
}

/* 管理员专属接口权限层：普通用户返回 403（未登录 401） */
function require_admin(): array {
    $u = require_auth();
    if ($u['role'] !== 'admin') {
        json_out(['ok' => false, 'error' => 'forbidden', 'msg' => '需要管理员权限'], 403);
    }
    return $u;
}

/* ============ CSRF / 同源校验（写操作统一入口） ============ */
/* 三层防护：① 同源（Origin/Referer 与站点比对）→ ② 自定义请求头 → ③ 会话绑定令牌 */
function check_same_origin(): void {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        if (strcasecmp(rtrim($origin, '/'), ALLOWED_ORIGIN) === 0) return;
        json_out(['ok' => false, 'error' => 'csrf', 'msg' => '请求来源不被允许'], 403);
    }
    $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref !== '') {
        $p = parse_url($ref);
        $base = strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? ''));
        if ($base !== '' && strcasecmp($base, ALLOWED_ORIGIN) === 0) return;
        json_out(['ok' => false, 'error' => 'csrf', 'msg' => '请求来源不被允许'], 403);
    }
    // 浏览器发起的写请求必带 Origin 或 Referer；两者皆无视为可疑请求
    json_out(['ok' => false, 'error' => 'csrf', 'msg' => '缺少请求来源信息'], 403);
}

function check_csrf(): void {
    check_same_origin();
    $h = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($h !== 'xmlhttprequest') {
        json_out(['ok' => false, 'error' => 'csrf', 'msg' => '缺少自定义请求头'], 403);
    }
    $t = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $s = (string)($_SESSION['csrf'] ?? '');
    if ($s === '' || $t === '' || !hash_equals($s, $t)) {
        json_out(['ok' => false, 'error' => 'csrf', 'msg' => '安全令牌无效或已过期，请刷新页面重试'], 403);
    }
}

/* ============ IP 级登录限速（防横向撞库/账号枚举） ============ */
/* 返回 [窗口内失败次数, 锁定截止时间]；锁定独立计时，窗口过期自动清零计数 */
function ip_guard_state(string $ip): array {
    $st = db()->prepare('SELECT fails, locked_until, last_attempt FROM nav_ipguard WHERE ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [0, 0];
    $fails = (int)$row['fails'];
    $lockedUntil = (int)$row['locked_until'];
    if ($fails > 0 && $lockedUntil <= time() && (int)$row['last_attempt'] < time() - IP_FAIL_WINDOW) {
        db()->prepare('UPDATE nav_ipguard SET fails = 0 WHERE ip = ?')->execute([$ip]);
        return [0, 0];
    }
    return [$fails, $lockedUntil];
}

/* 记一次失败；达到阈值写入 IP 锁定并审计 */
function ip_register_fail(string $ip): void {
    [$fails, $lockedUntil] = ip_guard_state($ip);
    $fails += 1;
    $lock = $fails >= IP_FAIL_THRESHOLD ? time() + IP_LOCK_SECONDS : max(0, $lockedUntil);
    db()->prepare('INSERT INTO nav_ipguard (ip, fails, locked_until, last_attempt) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until), last_attempt = VALUES(last_attempt)')
        ->execute([$ip, $fails, $lock, time()]);
    if ($fails >= IP_FAIL_THRESHOLD) {
        audit_log('ip_locked', '', 'fails=' . $fails);
    }
}

/* 登录成功后清除该 IP 的限速记录 */
function ip_clear(string $ip): void {
    db()->prepare('DELETE FROM nav_ipguard WHERE ip = ?')->execute([$ip]);
}

/* ============ 登录密码信封加密（会话级 RSA 密钥对） ============ */
/* 前端用 GET auth.php?action=pubkey 拿到的公钥（PKCS#1 v1.5）加密密码，服务端用会话内私钥解密。
   作用：请求体中不再出现明文密码（与 TLS 叠加的纵深防御）；密文与会话密钥对绑定，
   在同一会话内被截获的密文重放到其他会话会因解密失败而无效。 */
function rsa_ensure_keypair(): array {
    if (empty($_SESSION['rsa_pub']) || empty($_SESSION['rsa_priv'])) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) return ['', ''];
        $priv = '';
        if (!openssl_pkey_export($res, $priv)) return ['', ''];
        $details = openssl_pkey_get_details($res);
        if (!$details || empty($details['key'])) return ['', ''];
        $_SESSION['rsa_pub']  = $details['key'];
        $_SESSION['rsa_priv'] = $priv;
    }
    return [(string)$_SESSION['rsa_pub'], (string)$_SESSION['rsa_priv']];
}

function rsa_public_key(): string {
    [$pub] = rsa_ensure_keypair();
    return $pub;
}

/* 解密前端提交的密码密文（base64）；失败返回 null，调用方按登录失败处理 */
function rsa_decrypt_password(string $b64): ?string {
    if ($b64 === '' || empty($_SESSION['rsa_priv'])) return null;
    $raw = base64_decode($b64, true);
    if ($raw === false || $raw === '' || strlen($raw) > 512) return null;
    $plain = '';
    if (!openssl_private_decrypt($raw, $plain, $_SESSION['rsa_priv'])) return null;
    if (!is_string($plain) || $plain === '' || strlen($plain) > 200) return null;
    return $plain;
}

/* 登录成功后销毁会话内临时密钥对，收缩密文重放面 */
function rsa_drop_keypair(): void {
    unset($_SESSION['rsa_pub'], $_SESSION['rsa_priv']);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/* ============ 密码复杂度策略 ============ */
/* 口令最小长度 8 位；须由大写字母、小写字母、数字、特殊字符中至少 3 类组合；不得包含用户名 */
function password_policy_errors(string $p, string $username = ''): array {
    $errors = [];
    if (strlen($p) < 8) {
        $errors[] = '长度不足 8 位';
    }
    $kinds = 0;
    if (preg_match('/[a-z]/', $p)) $kinds++;
    if (preg_match('/[A-Z]/', $p)) $kinds++;
    if (preg_match('/[0-9]/', $p)) $kinds++;
    if (preg_match('/[^a-zA-Z0-9]/', $p)) $kinds++;
    if ($kinds < 3) {
        $errors[] = '须包含大写字母、小写字母、数字、特殊字符中的至少 3 类';
    }
    if ($username !== '' && stripos($p, $username) !== false) {
        $errors[] = '密码不能包含用户名';
    }
    return $errors;
}

/* 账号级登录失败处理参数：连续 LOCK_THRESHOLD 次失败锁定 LOCK_SECONDS 秒（数据库级，跨会话生效） */
const LOCK_THRESHOLD = 5;
const LOCK_SECONDS = 600;
