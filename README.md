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
| `members.role_id` / `manager_role_id` / `auto_repair` / `prune` | 成员对账：默认成员/负责人角色、缺失自动补齐、prune 默认开关（见「成员对账」） |
| `sync.resource` / `rate_limit_backoff_ms` / `max_rate_limit_retries` / `detail_timeout` / `stale_minutes` | 游标资源名、限流退避、单条详情超时、投影过期阈值 |
| `writeback.max_attempts` / `comment_template` / `comment_max_length` | 写回重试上限与评论模板 |

## 路由（`api/v1/yunxiao`）

- `connections`：列表、创建、更新、测试、轮换
- `projects`：列表、创建（H1）
- `projects/{id}/members`：成员列表（GET）、添加（POST）、移除（DELETE `/{userId}`）、
  按期望集合对账（POST `/sync`，issue #44）
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
- `common.yunxiao.member.sync`（成员添加/移除/对账）
- 兼容别名：`list` / `read` → 查看；`create` / `update` / `delete` → 管理

## Region OpenAPI 契约（实例域）

实例域（`*.devops.aliyuncs.com`）不拼 `organizationId`，且 projex 的列表语义是 `:search` POST + 复数集合 GET。
`GET /projects`、`GET /templates` 在实例域返回 404（issue #17，v3.10.6 及以前踩中）。

| 能力 | 方法 / 路径（相对 `/oapi/v1/projex`） | 备注 |
|---|---|---|
| 项目列表 | `POST /projects:search` | body `page` / `perPage`；返回数组 |
| 项目详情 | `GET /projects/{id}` | |
| 建项目 | `POST /projects` | `templateId` 取 `projectTemplates` 的 `id`；实例版 create 契约待实测（见 BL-183） |
| 模板列表 | `GET /projectTemplates` | 返回 `[{id,name,type}]` |
| 工作项列表 | `POST /workitems:search` | body `spaceId` / `category`=`Req,Task,Bug` / `page` / `perPage` / `orderBy` / `sort`；总数在 `x-total` 响应头 |
| 工作项详情 / 更新 | `GET` / `PUT /workitems/{id}` | |
| 工作项评论 | `POST /workitems/{id}/comments` | body `content` |
| 项目成员列表 | `GET /projects/{id}/members` | 返回 `[{userId,userName,roleId,roleName,userAvatar}]`；可用 `name` / `roleId` 过滤 |
| 添加项目成员 | `POST /projects/{id}/members` | body `roleId`（必填）+ `userIds[]`；成功**空响应体** |
| 移除项目成员 | `DELETE /projects/{id}/members` | DELETE 仍要 body `userId`（+ 可选 `roleIds[]`）；成功**空响应体** |

增量同步把游标（毫秒时间戳或 ISO）转成 `conditions`：`gmtModified` `BETWEEN [cursor, now]`，按 `gmtModified desc` 翻页。

## 成员对账（issue #44）

云效支持成员增删，所以平台不是「只能建项时带成员」：缺失成员有显式修复路径。

- **期望集合**：`project_manager` + `members`（云效 userId），写入
  `yunxiao_projects.metadata.expected_assignments`；负责人另存 `metadata.project_manager`。
  平台账号 → 云效 userId 的映射由宿主负责，本模块不做账号体系转换。
- **建项/幂等重入自动补齐**：`auto_repair=true`（默认）时，列出远端成员后把缺失的补上；
  负责人用 `members.manager_role_id`（默认 `project.admin`）单独添加，其余用
  `members.role_id`（默认 `project.participant`）批量添加。补写失败记 `assignment_failures`
  并置 `partial`，可再调 `POST projects/{id}/members/sync` 或建项接口重试。
- **部分更新**：只传 `members` 时 `project_manager` 继承现值，反之亦然；两侧都不传则按
  metadata 里的期望集合对账。
- **prune 边界**：`prune=true` 只移除 `metadata.managed_members`（平台添加过）中已不在期望
  集合的成员；**手工在云效加入的成员永不被移除**，默认 `prune=false`。
- **失败语义**：远端成员列表读不到时不补写（无法计算差集），全部按失败记录；单个移除失败
  记入 `failed_removals` 并保留 `managed_members` 重试资格；期望集合为空显式 422
  （`reason=member_list_empty`），不静默当成「无需成员」。
- **未实测边界**：成员写接口按云效 Region 版 API 参考实现，尚未用真实实例外呼验证；
  请求/响应形状由 `tests/Yunxiao/RegionYunxiaoClientTest.php` 锁定（含空响应体与 DELETE body）。
  写操作**没有 outbox/幂等键**：远端已成功、本地落盘失败时重试可能重复提交；云效对
  「添加已存在成员」的返回行为未实测，调用方不要把 add 当作严格幂等。

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
