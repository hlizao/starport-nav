<?php
// 星港导航 · 配置模板
// 使用方法：将本文件复制为 config.php，并填入你所在主机的数据库连接信息
// 注意：config.php 含有数据库凭据，切勿提交到公开仓库（项目已默认在 .gitignore 中排除）
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'your_database_name';
const DB_USER = 'your_database_user';
const DB_PASS = 'your_database_password';

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
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_auth(): void {
    start_session();
    if (empty($_SESSION['admin'])) {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

// 简易 CSRF 防护：写操作必须带自定义请求头（跨站表单无法伪造）
function check_csrf(): void {
    $h = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($h !== 'xmlhttprequest') {
        json_out(['ok' => false, 'error' => 'bad-request'], 400);
    }
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}