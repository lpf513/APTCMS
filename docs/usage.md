# APTCMS 使用说明（完整版）

## 1. 环境要求

- PHP >= 8.2（建议 8.3+）
- MySQL >= 8.0（生产推荐）
- 扩展：`mbstring`、`pdo_mysql`

## 2. 启动与访问

```bash
php -S 0.0.0.0:8080 -t .
```

常用地址：

- 前台首页：`http://127.0.0.1:8080/`
- 文章详情：`http://127.0.0.1:8080/article/ai-cms-design.html`
- 文章列表：`http://127.0.0.1:8080/article/index`
- 后台登录：`http://127.0.0.1:8080/admin/auth/login`
- 后台首页：`http://127.0.0.1:8080/admin/dashboard/index`
- 文章管理：`http://127.0.0.1:8080/admin/article/index`
- 模型管理：`http://127.0.0.1:8080/admin/model/index`
- 模型字段详情：`http://127.0.0.1:8080/admin/model/detail/article`
- 模型表单预览：`http://127.0.0.1:8080/admin/model/preview/article`
- 审计日志：`http://127.0.0.1:8080/admin/audit/index`（支持筛选与导出）
- 上传中心：`http://127.0.0.1:8080/admin/upload/index`
- 插件管理：`http://127.0.0.1:8080/admin/plugin/index`
- 系统配置：`http://127.0.0.1:8080/admin/setting/index`（需超级管理员）
- 安装向导：`http://127.0.0.1:8080/install/index/index`
- sitemap：`http://127.0.0.1:8080/sitemap.xml`
- robots：`http://127.0.0.1:8080/robots.txt`
- AI 内容接口：`http://127.0.0.1:8080/ai/content.json`
- AI 知识接口：`http://127.0.0.1:8080/ai/knowledge.json`

## 3. 后台默认账号

- 超级管理员：`admin / aptcms123`
- 编辑：`editor / editor123`

> 账号密码仅用于开发演示。当前配置采用 `password_hash` 存储，生产环境请迁移到数据库用户表并强制改密。

当 `config/app.php` 中 `db.enabled=true` 且完成安装后，后台登录与权限会优先读取数据库表 `apt_admin_user` 与 `apt_rbac_role_permission`。
模型定义会优先读取数据库表 `apt_model` 与 `apt_model_field`（配置文件作为回退）。
系统基础配置会在启动时读取 `config/app.php`，并在 `db.enabled=true` 时自动合并数据库表 `apt_system_setting`（按 key 覆盖，支持 `a.b.c` 点路径）。

## 4. 路由说明

- `/` → `home/HomeController@index`
- `/{controller}/{slug}.html` → `home/{Controller}@detail`
- `/admin/{controller}/{action}` → 后台控制器
- `/api/{controller}/{action}.json` → API 控制器
- `/ai/content.json`、`/ai/knowledge.json` → AI 标准抓取接口别名
- `/sitemap.xml` → SEO 站点地图
- `/robots.txt` → 搜索引擎爬虫规则
- `/healthz` → 系统健康检查

## 5. 模板语法

### 变量

```html
{$title}
{$json_ld|raw}
{$row.code}
```

### 条件

```html
{if $status == 1}
{elseif $status == 2}
{else}
{/if}
```

### 循环

```html
{list model="article" limit="10"}
  <li>{$title}</li>
{/list}

{loop items="$rows" as="row"}
  <li>{$row.name}</li>
{/loop}
```

### AI 标签

```html
{ai_summary /}
{ai_related limit="5" /}
```

## 6. 后台内容管理

- 支持文章新增/编辑/删除（CSRF + RBAC）。
- 通过 `ArticleService` 自动走 MySQL 或 JSON 模式。
- 支持模型字段详情与“表单预览”可视化，方便前后端协作。

## 7. 内容模型系统

当前模型定义在 `config/models.php`，字段类型已覆盖：

- text
- textarea
- editor
- image
- images
- number
- select
- radio
- checkbox
- datetime

每个字段支持 AI 扩展属性：`ai_understand`、`seo_weight`、`geo_weight`、`embedding`。

## 8. 数据源策略（JSON / MySQL 双模式）

`modules/article/ArticleService.php` 已实现 **MySQL 优先 + JSON 回退**：

- `config/app.php` 中 `db.enabled = false`：使用本地 `modules/article/data.json`
- `config/app.php` 中 `db.enabled = true`：优先使用 MySQL（PDO 预处理）
- `config/app.php` 中 `db.fallback_to_json = false`：数据库不可用时不再回退 JSON（严格 DB 模式）

## 9. AI API（分页 + 增量 + ETag）

支持参数：

- `page`
- `cursor`（等价页码游标，**不可与 `page` 同时使用**）
- `limit`（超过 `config.app.php` 的 `api.max_page_size` 会直接返回 `400`）
- `updated_after`（例如 `2026-01-01 00:00:00`）

响应包含 `ETag`，可配合 `If-None-Match` 做 304 增量拉取优化。
返回项补充 `url`、`checksum`（content）与 `updated_at`（knowledge）用于同步一致性。

示例：

```text
/ai/content.json?page=1&limit=20
/ai/content.json?cursor=2&limit=20
/ai/knowledge.json?page=1&limit=20&updated_after=2026-01-01 00:00:00
```

参数非法时返回 `400`，JSON 包含 `error`、`error_code=INVALID_QUERY_PARAM`、`invalid_param`、`request_id`（若可用）。
触发限流时返回 `429`，JSON 包含 `error`、`error_code=RATE_LIMITED`、`request_id`，并附带 `Retry-After` 响应头。

## 10. 请求与可观测

- 每个请求生成 `X-Request-Id` 便于排障追踪。
- 系统异常会写审计日志并返回包含 `request_id` 的错误响应。

## 11. 安全

系统已内置：

- XSS 转义输出
- CSRF Token（后台登录）
- 登录尝试次数限制
- 后台 IP 白名单（`security.admin_ip_allowlist`）
- 上传白名单（扩展名 + MIME）
- 后台密码哈希校验
- 审计日志滚动（默认 5MB 自动切分）

## 12. 数据库

- 核心示例 schema：`docs/sql/schema.sql`（包含 `apt_system_setting` 配置表）
- 当前演示数据：`modules/article/data.json`
- 系统安装时会将部分 `config/app.php` 关键项初始化到 `apt_system_setting`，用于后续 DB 配置覆盖。


## 12.1 插件声明规范（plugin.json）

插件元信息支持：

- `name`：插件名称
- `version`：插件版本（语义化）
- `description`：描述
- `aptcms`：对 CMS 版本约束（例如 `>=1.0.0`）
- `requires`：依赖插件列表（例如 `[{"code":"example","constraint":">=1.0.0"}]`）

当依赖或版本不匹配时，插件会被标记为不可加载，并写入审计日志事件 `plugin.skipped`。
插件后台支持启用/禁用/卸载（逻辑卸载）与恢复操作。
插件后台支持远程安装 URL（zip）与按 URL 升级。
后台新增“系统配置中心”可写入 `apt_system_setting`，支持站点名称、API 分页/限流、登录限流窗口、后台 IP 白名单等关键项维护；保存后写入审计事件 `system.settings.updated`，并记录旧值/新值差异。配置页同时支持按关键词/操作人/时间范围/条数筛选最近变更历史，并可一键导出 CSV（导出动作会写入审计事件 `system.settings.export`）；同时提供近 N 条变更键名统计（Top 10）。时间筛选支持 `YYYY-mm-dd HH:ii:ss` 与 `datetime-local`（如 `2026-01-01T12:00`）输入，非法值自动忽略。配置历史列表在筛选结果为空时会显示空态提示，并展示当前命中条数与统计项数量，便于快速判断筛选条件是否过窄。

## 13. 自测

```bash
rg --files -g '*.php' | xargs -n1 php -l
php scripts/selftest.php
php -r '$_SERVER["REQUEST_URI"]="/sitemap.xml"; require "index.php";'
php -r '$_SERVER["REQUEST_URI"]="/ai/content.json?page=1&limit=2"; require "index.php";'
php -r '$_SERVER["REQUEST_URI"]="/healthz"; require "index.php";'
```

## 14. 安装（MySQL）

1. 打开 `config/app.php`，设置 `db.enabled=true` 与数据库连接。
2. 访问 `/install/index/run` 执行建表和示例数据初始化。
3. 完成后将 `db.enabled` 保持为 true 进入 MySQL 模式。
