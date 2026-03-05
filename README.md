# APTCMS · AI 原生轻量级 CMS

APTCMS 是一个基于 **PHP + MySQL** 的轻量、模块化、AI 原生 CMS。

## 当前完成度（持续增强版）

- 核心框架：Router / Controller / View / Template / Hook / Cache / Security / Auth
- 模板引擎：变量、条件、循环、列表、AI 标签、`|raw`、对象字段访问（`{$row.code}`）
- 内容模块：Article 示例模块（列表/详情）
- 数据源能力：MySQL 优先 + JSON 回退（`db.enabled` 切换）
- AI 能力：SEO Agent + GEO Agent
- AI 接口：`/ai/content.json`、`/ai/knowledge.json`（分页 + 增量 + cursor + ETag/304）
- SEO 能力：`/sitemap.xml`（lastmod/changefreq/priority）+ `/robots.txt`
- 运维能力：`/healthz` 健康检查、`/install/index/run` 安装向导、请求级 `request_id`
- 后台能力：登录、会话、RBAC、文章管理 CRUD（状态筛选/搜索/排序/分页/批量操作）、模型管理与字段详情/表单预览、审计日志、上传中心、插件管理
- 插件系统：自动加载 `plugins/*/bootstrap.php`，支持 plugin.json 版本约束、依赖校验、生命周期与远程安装/升级
- 文档与自测：系统设计、使用说明、数据库 schema、优化建议、自测脚本

## 快速启动

```bash
php -S 0.0.0.0:8080 -t .
```

打开：`http://127.0.0.1:8080/`

## 文档

- 系统设计：`docs/system-design.md`
- 使用说明：`docs/usage.md`
- 数据库结构：`docs/sql/schema.sql`
- 持续优化建议：`docs/optimization-notes.md`

## 自测

```bash
rg --files -g '*.php' | xargs -n1 php -l
php scripts/selftest.php
```
