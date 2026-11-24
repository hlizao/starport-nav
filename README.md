# 星港导航 Starport

个人导航站（深色科技感）+ PHP/MariaDB 登录后台。访客只读浏览；管理员登录后可增删改站点链接与便签，数据保存在服务端数据库，**所有访客共享最新内容**。

## 功能特性

- **指挥台首页**：实时时钟 + 分时段问候；`/` 或 `Ctrl+K` 聚焦搜索
- **五个搜索引擎**：百度 / 必应 / 知乎 / B站 / 微博，一键切换；输入网址直接跳转；**搜索在新标签页打开**，导航页本身不被打断
- **两组站点卡片**：办公协同（邮箱/文档/网盘/会议）+ 娱乐与生活（视频/音乐/购物/出行）
- **管理员登录**：bcrypt 密码哈希存服务端，Session 会话（HttpOnly + SameSite），源码不含任何凭据
- **登录后解锁**：编辑模式（增删改/撤销/恢复默认）+ 便签（自动保存）
- **访客只读**：未登录时所有写接口返回 401，防暴力破解（连错 5 次锁 60 秒）
- **零前端依赖**：单文件 HTML + 原生 JS，无框架无外部字体

## 技术架构

```
浏览器（index.html，纯静态前端）
      │  fetch JSON
      ▼
PHP API（api/：auth / links / note）
      │  PDO
      ▼
MariaDB（nav_links / nav_note / nav_admin）
```

| 接口 | 方法 | 说明 |
|------|------|------|
| `api/auth.php` | `POST {action:"login"/"logout"}` | 登录 / 登出；`GET ?action=status` 查询会话状态 |
| `api/links.php` | `GET` / `POST` | 公开读取全部链接；登录后整份替换保存 |
| `api/note.php` | `GET` / `POST` | 登录后读写便签 |

## 目录结构

```
├── index.html                  # 前端（单文件）
├── api/
│   ├── config.sample.php       # 配置模板（复制为 config.php 并填入数据库信息）
│   ├── config.php              # 本地配置（含凭据，.gitignore 已排除，不提交）
│   ├── auth.php                # 登录/登出/会话状态
│   ├── links.php               # 链接数据接口
│   └── note.php                # 便签接口
├── deploy/                     # 可选：本机 + Cloudflare 隧道部署脚本
│   ├── start-nav.ps1
│   ├── keepalive-nav.ps1
│   └── 启动导航站.bat
└── docs/
    ├── 星港导航站公网部署技术文档.md         # Cloudflare 隧道部署方案技术文档
    └── 星港导航站前后端部署运维技术文档.md   # 虚拟主机 PHP+MariaDB 部署/验收/运维文档
```

## 部署到虚拟主机（PHP 8 + MariaDB）

1. 上传 `index.html` 与 `api/`（含 PHP 文件）到站点根目录（如 `public_html/`）
2. 复制 `api/config.sample.php` → `api/config.php`，填入数据库主机/库名/账号/密码
3. 在数据库中创建表（SQL 见下方附录），并写入管理员账号：
   - 生成密码哈希：`php -r "echo password_hash('你的密码', PASSWORD_BCRYPT);"`
   - 写入：`INSERT INTO nav_admin (id, username, pass_hash) VALUES (1, 'admin', '<生成的哈希>');`
4. 访问域名 → 右上角「登录」→ 账号 `admin` + 你设置的密码
5. 首次使用：登录 → 编辑 → 增删站点，内容自动保存到数据库，所有访客可见

## 附录：数据库建表 SQL

```sql
CREATE TABLE IF NOT EXISTS nav_links (
  id VARCHAR(20) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  url VARCHAR(500) NOT NULL,
  domain VARCHAR(200) NOT NULL,
  grp VARCHAR(20) NOT NULL DEFAULT 'office',
  c1 VARCHAR(20) DEFAULT '#3B82F6',
  c2 VARCHAR(20) DEFAULT '#818CF8',
  pos INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nav_note (
  id INT PRIMARY KEY,
  content TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nav_admin (
  id INT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  pass_hash VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 安全说明

- `api/config.php` 含数据库凭据，已在 `.gitignore` 中排除，**切勿提交**
- 密码仅存 bcrypt 哈希；Session Cookie 设置 HttpOnly + SameSite=Lax（HTTPS 下 Secure）
- 所有写接口要求登录会话 + 自定义请求头（CSRF 防护）+ 输入校验
- 登录失败限速：同一会话 60 秒内连续失败 5 次即锁定，每次失败固定 300ms 延迟
- 直接双击 `index.html`（无后端）时仅显示默认数据、只读

## 环境

- 前端：任意现代浏览器
- 后端：PHP 8.x（mysqli / PDO）+ MariaDB / MySQL