<?php
// 星港导航 · 日志审计查询接口（仅登录可查）
// 管理员：可见全部审计日志；普通用户：仅可见本人操作日志（服务端强制 WHERE，前端不可越权）
// GET 参数：page（默认 1）、size（默认 50，上限 100）、action（可选类型筛选，支持 'lock' 聚合锁定类事件）
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['ok' => false, 'error' => 'bad-request'], 400);
}

$u = require_auth(); // 未登录 401

$page = max(1, (int)($_GET['page'] ?? 1));
$size = (int)($_GET['size'] ?? 50);
if ($size < 1) $size = 50;
if ($size > 100) $size = 100; // 分页大小上限
$action = trim((string)($_GET['action'] ?? ''));
if ($action !== '' && !preg_match('/^[a-z_]{1,32}$/', $action)) $action = '';

// 合法动作白名单（用于 action 精确筛选；'lock' 为聚合值）
const AUDIT_ACTIONS = [
    'login_ok', 'login_fail', 'logout', 'create_user', 'delete_user',
    'change_pass', 'change_role', 'unlock', 'ip_locked', 'ip_blocked',
    'account_locked', 'account_blocked',
];
if ($action !== '' && $action !== 'lock' && !in_array($action, AUDIT_ACTIONS, true)) $action = '';

$isAdmin = $u['role'] === 'admin';

// 构建 WHERE：角色隔离（核心安全边界）+ 类型筛选
$where  = [];
$bind   = [];
if (!$isAdmin) {
    $where[] = 'actor = ?';
    $bind[]  = $u['username'];
}
if ($action === 'lock') {
    $where[] = "(action IN ('ip_locked','ip_blocked','account_locked','account_blocked'))";
} elseif ($action !== '') {
    $where[] = 'action = ?';
    $bind[] = $action;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();
$st = $pdo->prepare('SELECT COUNT(*) FROM nav_audit' . $whereSql);
$st->execute($bind);
$total = (int)$st->fetchColumn();
$pages  = max(1, (int)ceil($total / $size));
if ($page > $pages) $page = $pages;

$offset = ($page - 1) * $size;
$st = $pdo->prepare('SELECT id, ts, actor, ip, action, detail FROM nav_audit' . $whereSql . ' ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . $offset);
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$logs = array_map(function ($r) use ($isAdmin) {
    return [
        'id'     => (int)$r['id'],
        'ts'     => (int)$r['ts'],
        'actor'  => (string)$r['actor'],
        'ip'     => (string)$r['ip'],
        'action' => (string)$r['action'],
        'detail' => (string)$r['detail'],
    ];
}, $rows);

json_out([
    'ok'     => true,
    'scope'  => $isAdmin ? 'all' : 'self',
    'role'   => $u['role'],
    'logs'   => $logs,
    'total'  => $total,
    'page'   => $page,
    'pages'  => $pages,
    'size'   => $size,
]);
