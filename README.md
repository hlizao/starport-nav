# 星港导航 Starport

个人导航站（深色科技感）+ PHP/MariaDB 登录后台，支持**管理员 / 普通用户双角色权限体系**：访客与任何未登录者看到的都是原始导航首页（仅管理员可编辑）；普通用户登录后看到自己的定制化导航页（仅本人可编辑）。

## 角色与权限模型

| 能力 | 访客 | 普通用户 | 管理员 |
|------|------|---------|--------|
| 浏览原始导航首页 | ✓ | ✓ | ✓ |
| 搜索/时钟/直达网址 | ✓ | ✓ | ✓ |
| 登录后查看自己的定制导航页 | — | ✓ | ✓（管理员即原始首页） |
| 编辑自己的导航页 | — | ✓ | ✓（即原始首页） |
| 编辑原始导航首页 | — | ✕（服务端 403） | ✓ |
| 个人书签 / 便签 | — | ✓ | ✓ |
| 账号管理（创建/改密/解锁/删除） | — | ✕（服务端 403） | ✓ |

> 普通用户首次登录时，服务端自动从原始首页复制一份作为其个人页起点；各用户的导航页/书签/便签在数据库按 owner 隔离，互不可见互不干扰。所有权限在服务端强制校验，普通用户无法通过接口越权（未登录 401、越权 403）。

## 功能特性

- **指挥台首页**：实时时钟 + 分时段问候；`/` 或 `Ctrl+K` 聚焦搜索
- **五个搜索引擎**：百度 / 必应 / 知乎 / B站 / 微博，一键切换；输入网址直接跳转；**搜索在新标签页打开**，导航页本身不被打断
- **两组站点卡片**：办公协同（邮箱/文档/网盘/会议）+ 娱乐与生活（视频/音乐/购物/出行）
- **管理员登录**：bcrypt 密码哈希存服务端，Session 会话（HttpOnly + SameSite），源码不含任何凭据
- **账号管理与安全策略（仅管理员）**：多账号（唯一用户名唯一索引、可选角色）；密码复杂度策略（≥8位 + 大写/小写/数字/特殊字符至少3类，前后端双重校验+实时指示器）；连续 5 次登录失败锁定 10 分钟（数据库级、跨会话/跨设备生效），管理员可提前解锁；删除保护（禁删自己/最后账号，删除时连带清理该用户个人数据）
- **书签管理器（登录后）**：仿 Chrome 书签管理器——文件夹分组、搜索过滤、添加/编辑/删除（带撤销），点击新标签页打开；数据存服务端，多设备同步
- **登录后解锁**：编辑模式（增删改/撤销/恢复默认）+ 便签（自动保存）+ 书签
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
| `api/links.php` | `GET` / `POST` | GET：访客/管理员读原始首页，普通用户读自己的页（首次自动复制）；POST：管理员存原始首页，普通用户存自己的页（服务端强制归属） |
| `api/note.php` | `GET` / `POST` | 登录后读写本人便签（按用户隔离） |
| `api/bookmarks.php` | `GET` / `POST` | 登录后读写本人书签（按用户隔离） |
| `api/admin.php` | `GET` / `POST` | **管理员专属**（普通用户 403）：账号列表/创建/改密/解锁/删除 |

## 目录结构

```
├── index.html                  # 前端（单文件）
├── api/
│   ├── config.sample.php       # 配置模板（复制为 config.php 并填入数据库信息）
│   ├── config.php              # 本地配置（含凭据，.gitignore 已排除，不提交）
│   ├── auth.php                # 登录/登出/会话状态
│   ├── links.php               # 链接数据接口
│   ├── note.php                # 便签接口
│   ├── bookmarks.php           # 书签接口
│   └── admin.php                # 账号管理接口（唯一性/复杂度/锁定解锁）
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
   - 生成密码哈希（密码需满足：至少 8 位，且包含大写字母、小写字母、数字、特殊字符中至少 3 类）：`php -r "echo password_hash('你的密码', PASSWORD_BCRYPT);"`
   - 写入：`INSERT INTO nav_admin (username, pass_hash) VALUES ('admin', '<生成的哈希>');`
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
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  pass_hash VARCHAR(100) NOT NULL,
  failed_count INT NOT NULL DEFAULT 0,
  locked_until INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 安全说明

- `api/config.php` 含数据库凭据，已在 `.gitignore` 中排除，**切勿提交**
- 密码仅存 bcrypt 哈希；Session Cookie 设置 HttpOnly + SameSite=Lax（HTTPS 下 Secure）
- **密码复杂度强校验**：≥8 位 + 大写/小写/数字/特殊字符至少 3 类（创建/改密时后端强制，前端实时指示）
- **登录失败处理**：连续 5 次失败锁定 10 分钟（数据库级，跨会话/设备生效），登录成功清零计数；管理员可解锁；失败固定 300ms 延迟，不存在账号统一模糊提示（防枚举）
- **用户名唯一**：数据库 UNIQUE 索引 + 创建时重复检查（409 冲突提示）
- 所有写接口要求登录会话 + 自定义请求头（CSRF 防护）+ 输入校验
- 登录失败限速：同一会话 60 秒内连续失败 5 次即锁定，每次失败固定 300ms 延迟
- 直接双击 `index.html`（无后端）时仅显示默认数据、只读

## 环境

- 前端：任意现代浏览器
- 后端：PHP 8.x（mysqli / PDO）+ MariaDB / MySQL