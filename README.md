# Yunxiao 集成（阿里云效连接器）

云效项目、工作项与模板映射投影，以及把 GitLab 事件对账写回云效。连接、游标/outbox、inbox 全部复用 Integration，模块不建第二套连接或同步表。

## 分层与依赖

- 架构层：`common`（`config/module-architecture.php`）
- 依赖模块：`integration`
- 冲突声明：无
- 迁移：`2025_01_03_000019_yunxiao_module.php`（3 张投影表）、`2025_01_04_000019_yunxiao_module_data.php`（RBAC 权限，幂等）

## 配置 `config/yunxiao.php`

| 键 | 说明 |
|---|---|
| `enabled` / `per_page` | 模块开关、分页；路由固定为 `api/v1/yunxiao` |
| `api.base_url` / `auth_style` / `timeout` / `rate_limit` / `page_size` / `max_pages` | 云效 API 与限流参数 |
| `security.allow_http` / `allow_private_hosts` / `allowed_host_suffixes` / `blocked_hosts` | SSRF 防护；默认只允许 HTTPS 与云效域名后缀 |
| `sync.resource` / `rate_limit_backoff_ms` / `max_rate_limit_retries` / `detail_timeout` / `stale_minutes` | 游标资源名、限流退避、单条详情超时、投影过期阈值 |
| `writeback.max_attempts` / `comment_template` / `comment_max_length` | 写回重试上限与评论模板 |

## 路由（`api/v1/yunxiao`）

- `connections`：列表、创建、更新、测试、轮换
- `projects`：列表、创建（H1）
- `workitems`：列表、详情、更新
- `sync`：手动同步工作项
- `writebacks`：列表、`{id}/retry`（死信/失败重试）
- `template-maps`：列表、创建、更新

## 能力点

默认权限集：`yunxiao.view` / `yunxiao.manage`。

- `common.yunxiao.connection.view` / `connection.manage`
- `common.yunxiao.project.view` / `project.create`
- `common.yunxiao.workitem.view` / `workitem.sync`
- `common.yunxiao.template_map.manage`
- `common.yunxiao.writeback.view` / `writeback.retry`
- `common.yunxiao.member.sync`
- 兼容别名：`list` / `read` → 查看；`create` / `update` / `delete` → 管理

## 同步与写回

- 工作项同步按连接 + `integration_sync_cursors(resource=workitems)` 增量拉取，限流退避后重试；超过 `stale_minutes` 的投影标记为 stale。
- 写回通过 Integration outbox 入队（`workitem.writeback_status` / `workitem.writeback_comment`），成功 `markSent`、失败 `markFailed`，达到 `max_attempts` 进死信，可经接口重试。
- 状态映射来自模板 `status_map`；无映射或未知状态时只评论不改状态（`comment_only`）。
- 冲突（远端版本已变化）先重新拉取再重试一次，避免覆盖他人修改。

## 数据表

`yunxiao_projects`、`yunxiao_workitems`、`yunxiao_template_maps`。连接/凭据、游标、outbox、inbox、同步运行记录均在 Integration。

## 安全

- 凭据只写 `integration_connections.credentials`（`encrypted:array`），响应只回脱敏字段。
- 禁止 root / 个人 token 做系统集成；使用最小权限应用 token。
- 客户端拒绝非 HTTPS、内网地址与非法跳转；错误与日志脱敏 token。
- AI 工具只读/起草：`yunxiao_writeback_comment_draft` 仅返回草稿，提交必须由用户确认后走 outbox。

## 命令

- `yunxiao:sync-workitems`：按连接增量同步工作项
- `yunxiao:retry-writebacks`：重试失败的写回消息
- `yunxiao:verify-template`：校验模板/状态映射

## 测试

```bash
php artisan test --parallel --processes=2 tests/Yunxiao
```

真实云效验证为可选门：`RUN_EXTERNAL_TESTS=1 EXTERNAL_TEST_TARGET=yunxiao`，测试资源必须使用 `pmtest-` 前缀，禁止触碰生产空间。
