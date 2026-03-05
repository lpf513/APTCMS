# APTCMS 完整系统设计（AI 自动开发专用版）

## 1. 产品定位

APTCMS 是一套基于 PHP + MySQL 的 AI 原生轻量 CMS，目标是让团队在不依赖重框架的前提下，快速构建 SEO/GEO 友好的网站。

### 不可违背目标

- 轻量：核心层小而稳，依赖最少。
- 高性能：显式 SQL、缓存优先、禁止隐式加载。
- 强扩展：模块、插件、AI 层解耦。
- AI 原生：AI 能力内置到发布链路。
- 前端友好：模板系统前端可独立开发。
- 非程序员可用：后台可视化模型与字段管理。
- 长期演进：不绑定单一 AI 服务商。

---

## 2. 总体架构

```text
前端模板层  themes/
   ↓
应用模块层  app/ + modules/
   ↓
AI 引擎层    ai/
   ↓
核心框架层  core/
   ↓
数据层      MySQL + Cache
```

### 分层约束

1. `core/` 不依赖 `ai/`。
2. `ai/` 仅通过 Hook/Service 介入业务。
3. 模块（业务能力）与插件（增强扩展）严格分离。

---

## 3. 标准目录

```text
/
├─ index.php
├─ core/
│  ├─ App.php
│  ├─ Router.php
│  ├─ Controller.php
│  ├─ Model.php
│  ├─ View.php
│  ├─ Template.php
│  ├─ DB.php
│  ├─ Cache.php
│  ├─ Hook.php
│  └─ Security.php
├─ app/
│  ├─ admin/
│  ├─ home/
│  ├─ api/
│  └─ install/
├─ modules/
│  ├─ article/
│  ├─ product/
│  └─ page/
├─ ai/
│  ├─ Driver/
│  ├─ Prompt/
│  ├─ Agent/
│  ├─ Task/
│  └─ Vector/
├─ plugins/
├─ themes/
├─ config/
├─ cache/
├─ upload/
└─ public/
```

---

## 4. 核心框架设计

### 4.1 Router

- 支持语义化 URL：`/article/ai-cms-design.html`
- 伪静态路由映射
- 使用简单路径分段规则，避免复杂正则

路由解析策略：

1. 去除 query string
2. trim `/`
3. 按 `/` 分段
4. 映射到 `app/{app}/Controller/{Controller}Controller::{action}`

### 4.2 Controller

职责：请求接收、参数校验、调用模型、赋值模板、输出响应。

控制器规范：

- 不直接拼接 SQL。
- 仅处理编排逻辑。
- 与 AI 交互通过 Service/Hook。

### 4.3 Model

- 一个模型对应一张主表。
- 禁止隐式 JOIN。
- 所有 SQL 显式声明。
- 所有数据写入走白名单字段。

---

## 5. 内容模型系统

内容模型由以下元数据构成：

1. 模型基础信息（name/table/module）
2. 字段定义（类型、校验、索引）
3. 表单渲染规则
4. 模板绑定
5. AI 行为定义（SEO/GEO/embedding）

### 字段类型（必选）

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

### AI 字段属性（关键）

- `ai_understand`: 是否参与 AI 理解
- `seo_weight`: SEO 语义权重
- `geo_weight`: GEO 语义权重
- `embedding`: 是否生成向量

---

## 6. 模板系统

### 目标

- 前端仅写 HTML + 标签语法。
- 不允许前端模板中出现 PHP。

### 标签规范

- 变量：`{$title}`
- 列表：`{list model="article" limit="10"}...{/list}`
- 条件：`{if $status == 1}...{/if}`
- AI 标签：`{ai_summary /}`、`{ai_related limit="5" /}`

### 编译机制

1. 模板 HTML 解析为受控 PHP 文件
2. 编译缓存写入 `cache/`
3. 比较模板文件 mtime 实现自动刷新

---

## 7. 后台管理系统

### 功能分区

- 内容管理
- 模型管理
- 栏目管理
- 模板管理
- AI 设置
- SEO/GEO 设置
- 插件管理
- 系统设置

### 权限体系

- 用户
- 角色
- 权限（菜单 + 操作）

采用 RBAC，权限点示例：

- `content.article.create`
- `content.article.publish`
- `system.plugin.install`

---

## 8. AI 原生能力设计

### 8.1 Driver 抽象

```php
interface AiDriver {
    public function chat(array $messages): string;
    public function embedding(string $text): array;
}
```

通过配置切换具体厂商驱动，避免绑定单一服务商。

### 8.2 Prompt 管理

- Prompt 模板可配置
- 支持变量注入
- 支持按任务类型切换模型

### 8.3 Agent 体系

- SEO Agent
- GEO Agent
- 内容编辑 Agent
- 结构优化 Agent

统一执行链路：

`事件触发 -> 条件判断 -> 执行动作 -> 写回数据`

---

## 9. SEO + GEO 自动化（系统级）

### SEO 自动化

- 自动 title/description
- canonical 自动生成
- sitemap.xml 增量维护
- 内链建议
- 页面结构校验

### GEO 自动化

- AI 摘要
- Q&A 结构化输出
- JSON-LD Schema 输出
- 作者/来源/时间明确化

### AI 抓取接口

- `/ai/content.json`
- `/ai/knowledge.json`

约束：必须稳定、可分页、可增量同步。

---

## 10. 数据库设计原则

1. 核心表保持少量稳定
2. 模型数据表按业务扩展
3. AI 数据独立存储
4. SEO/GEO 元数据独立存储

推荐分组：

- `apt_content_*`
- `apt_model_*`
- `apt_ai_*`
- `apt_seo_*`
- `apt_geo_*`

---

## 11. 安全基线（必须）

- PDO 预处理防 SQL 注入
- 输出统一 XSS 过滤
- CSRF Token 机制
- 上传白名单与 MIME 校验
- 后台登录限流 + 锁定
- 后台 IP 白名单访问控制
- AI 接口请求频率限制
- 操作日志与审计追踪
- 审计日志自动滚动与归档

---

## 12. 性能基线

- 不使用 ORM
- SQL 显式
- 缓存优先（页面缓存 + 数据缓存）
- 禁止隐式加载
- 核心代码小而稳

关键指标（建议）：

- 首屏 TTFB < 200ms（缓存命中）
- 首页查询次数 <= 10（普通内容页）
- 模板编译缓存命中率 > 95%

---

## 13. AI 自动开发适配

本方案可直接作为 AI 开发提示文档，原因：

- 结构稳定
- 边界清晰
- 规则明确
- 可阶段化生成与验收

---

## 14. 推荐开发顺序

1. core 框架
2. 路由 / 控制器
3. 模型系统
4. 模板引擎
5. 后台系统
6. AI Driver
7. SEO / GEO Agent

每阶段输出要求：

- 代码
- 数据表变更
- 自动化测试
- 性能与安全检查记录
