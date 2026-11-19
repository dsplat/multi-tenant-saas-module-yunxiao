# Yunxiao 集成（阿里云效连接器）

云效项目、工作项与模板映射投影，把 GitLab 事件对账写回云效，提供账号级组织发现/成员/部门/角色只读能力，并支持把 AI 拆解的业务需求导入云效工作项。连接、游标/outbox、inbox 全部复用 Integration，模块不建第二套连接或同步表。

## 分层与依赖

- 架构层：`common`（`config/module-architecture.php`）
- 依赖模块：`integration`
- 冲突声明：无
- 迁移：`2025_01_03_000019_yunxiao_module.php`（3 张投影表）、`2025_01_04_000019_yunxiao_module_data.php`（RBAC 权限，幂等）、`2026_11_19_000019_yunxiao_workitem_imports.php`（需求导入账本，issue #1）

## 配置 `config/yunxiao.php`

| 键 | 说明 |
|---|---|
| `enabled` / `per_page` | 模块开关、分页；路由固定为 `api/v1/yunxiao` |
| `api.base_url` / `api.central_base_url` / `auth_style` / `timeout` / `rate_limit` / `page_size` / `max_pages` | 云效 API 与限流参数；Region 与中心站（Central / Account）默认接入点分列 |
| `security.allow_http` / `allow_private_hosts` / `allowed_host_suffixes` / `blocked_hosts` | SSRF 防护；默认只允许 HTTPS 与云效域名后缀 |
| `members.role_id` / `manager_role_id` / `auto_repair` / `prune` | 成员对账：默认成员/负责人角色、缺失自动补齐、prune 默认开关（见「成员对账」） |
| `sync.resource` / `rate_limit_backoff_ms` / `max_rate_limit_retries` / `detail_timeout` / `stale_minutes` | 游标资源名、限流退避、单条详情超时、投影过期阈值 |
| `writeback.max_attempts` / `comment_template` / `comment_max_length` | 写回重试上限与评论模板 |
| `import.max_items` / `import.priority_map` | 需求导入单批上限；业务优先级别名 → 云效优先级字段值 ID（见「需求导入云效」） |

## 路由（`api/v1/yunxiao`）

- `connections`：列表、创建、更新、测试、轮换
- `organizations` / `organizations/{orgId}/members|departments|roles` / `user`：账号级组织只读（issue #46）
- `projects`：列表、创建（H1）
- `projects/{id}/members`：成员列表（GET）、添加（POST）、移除（DELETE `/{userId}`）、
  按期望集合对账（POST `/sync`，issue #44）
- `projects/{id}/sprints|milestones|risks|progress`：项目执行读取（GET，只读，issue #48）
- `workitems`：列表、详情、更新
- `projects/{id}/workitems/import`：需求导入云效（创建工作项，issue #1）
- `sync`：手动同步工作项
- `writebacks`：列表、`{id}/retry`（死信/失败重试）
- `template-maps`：列表、创建、更新

## 能力点

默认权限集：`yunxiao.view` / `yunxiao.manage`。

- `common.yunxiao.connection.view` / `connection.manage`
- `common.yunxiao.project.view` / `project.create`
- `common.yunxiao.workitem.view` / `workitem.sync` / `workitem.import`（需求导入，写）
- `common.yunxiao.template_map.manage`
- `common.yunxiao.writeback.view` / `writeback.retry`
- `common.yunxiao.member.sync`（成员添加/移除/对账）
- `common.yunxiao.organization.view`（账号级组织/成员/部门/角色只读）
- 兼容别名：`list` / `read` → 查看；`create` / `update` / `delete` → 管理

## projex OpenAPI 契约（Region / Central）

云效 projex 有两条接入点，wire shape 相同，只差路径前缀与默认域名：

| scope | 默认 base_url | 路径前缀 |
|---|---|---|
| `Region` | `https://{org}.devops.aliyuncs.com` | `/oapi/v1/projex`（不拼 organizationId） |
| `Central` | `https://openapi-rdc.aliyuncs.com` | `/oapi/v1/projex/organizations/{organizationId}`（必拼，来自 `config.org_id`） |
| `Account` | `https://openapi-rdc.aliyuncs.com` | 项目级调用拼 `organizations/{default_org_id}`；组织级走 `/oapi/v1/platform`（见下节） |

连接创建/更新时 `config.scope=Central` **必须**带 `config.org_id`（中心组织管理后台「基本信息」的组织 ID），
缺失即 422/配置错误，不会发请求。Central 使用该组织创建的个人访问令牌；Region 令牌与中心令牌不通用
（issue #45 实测：Region 令牌打中心路径 401 InvalidToken）。`base_url` 省略时按 scope 取默认接入点；
显式填写自定义域名时保持不动（SSRF 白名单仍只放行 `*.devops.aliyuncs.com` 与 `openapi-rdc.aliyuncs.com`）。

表内路径均相对上述「路径前缀」；Region 实例域不拼 `organizationId`，且列表语义是 `:search` POST + 复数集合 GET。
`GET /projects`、`GET /templates` 在实例域返回 404（issue #17，v3.10.6 及以前踩中）。

| 能力 | 方法 / 路径（相对路径前缀） | 备注 |
|---|---|---|
| 项目列表 | `POST /projects:search` | body `page` / `perPage`；返回数组 |
| 项目详情 | `GET /projects/{id}` | |
| 建项目 | `POST /projects` | `templateId` 取 `projectTemplates` 的 `id`；实例版 create 契约待实测（见 BL-183） |
| 模板列表 | `GET /projectTemplates` | 返回 `[{id,name,type}]` |
| 工作项列表 | `POST /workitems:search` | body `spaceId` / `category`（默认 `Req,Task,Bug`，可含 `Risk`/`Request`/`Topic`）/ `page` / `perPage` / `orderBy` / `sort`；总数在 `x-total` 响应头 |
| 迭代列表 | `GET /projects/{id}/sprints` | 官方 `ListSprints`；`status`=`TODO,DOING,ARCHIVED` / `page` / `perPage`；总数在 `x-total` |
| 里程碑列表 | `GET /projects/{id}/milestones` | 官方 `ListMilestones`；`status` / `page` / `perPage`；总数在 `x-total` |
| 工作项详情 / 更新 | `GET` / `PUT /workitems/{id}` | |
| 工作项创建 | `POST /workitems` | body `spaceId` / `subject` / `assignedTo` / `workitemTypeId` 必填；`priority` 走 `customFieldValues.priority`；响应仅 `{id}` |
| 工作项评论 | `POST /workitems/{id}/comments` | body `content` |
| 项目成员列表 | `GET /projects/{id}/members` | 返回 `[{userId,userName,roleId,roleName,userAvatar}]`；可用 `name` / `roleId` 过滤 |
| 添加项目成员 | `POST /projects/{id}/members` | body `roleId`（必填）+ `userIds[]`；成功**空响应体** |
| 移除项目成员 | `DELETE /projects/{id}/members` | DELETE 仍要 body `userId`（+ 可选 `roleIds[]`）；成功**空响应体** |

增量同步把游标（毫秒时间戳或 ISO）转成 `conditions`：`gmtModified` `BETWEEN [cursor, now]`，按 `gmtModified desc` 翻页。

## 账号级组织能力（issue #46）

连接 `config.scope=Account`（阿里云主账号账号级个人访问令牌）时，模块通过 `/oapi/v1/platform` 提供只读组织能力：

| 能力 | 方法 / 路径 | 端点 |
|---|---|---|
| 组织列表 | `GET /oapi/v1/platform/organizations` | `GET api/v1/yunxiao/organizations` |
| 当前用户 | `GET /oapi/v1/platform/user` | `GET api/v1/yunxiao/user` |
| 组织成员 | `GET /oapi/v1/platform/organizations/{orgId}/members` | `GET .../organizations/{orgId}/members` |
| 组织部门 | `GET /oapi/v1/platform/organizations/{orgId}/departments` | `GET .../organizations/{orgId}/departments` |
| 组织角色 | `GET /oapi/v1/platform/organizations/{orgId}/roles` | `GET .../organizations/{orgId}/roles` |

连接配置（只对 `scope=Account` 生效）：

- `config.default_org_id`：项目级 projex 调用（建项/工作项/项目成员）使用的组织；不配则项目级调用直接拒绝（`org_missing`），组织级接口不受影响。
- `config.org_allowlist`：组织白名单。非空时列表只返回白名单内组织，单组织接口对白名单外组织返回 422（`org_not_allowed`）；`default_org_id` 必须在白名单内。

归一化键：组织 `organization_id / name`；当前用户 `user_id / name / email / last_organization_id`；
成员 `user_id / name / email / status / role_id / role_ids / department_id / department_ids / joined_at`；
部门 `department_id / name / parent_id / creator_id / has_children`；角色 `role_id / name / organization_id / permissions`。
成员分页支持 `page/per_page` 或 `max_results/next_token`；部门支持 `parent_id`。

**operator 映射边界**：`operator_id ↔ 云效 user_id` 的映射表（`yunxiao_member_maps`）由宿主维护，本模块不做账号体系转换；
下游用成员列表的 `email` / `name` 做「邮箱优先 + 姓名候选 + 管理员确认」，把映射后的云效 `userId` 传给
`createProject.memberIds` 或项目成员接口。邮箱可能缺失（实测 3/6 无邮箱），不能只靠邮箱自动匹配。

## 项目执行读取（issue #48）

下游周报 / 进度告警需要读云效「计划与执行」数据；云效 projex 公开接口里：

- 迭代：`ListSprints` —— `GET /projects/{id}/sprints`（中心版拼 `organizations/{organizationId}`）；
- 里程碑：`ListMilestones` —— `GET /projects/{id}/milestones`；
- 风险：**没有独立资源**，风险是工作项大类 `category=Risk`；
- 项目进度：**没有统计接口**，由工作项聚合（官方 API 目录中也无 statistics/progress 端点）。

路由（`{id}` 是 `yunxiao_project_id` 投影主键，不是云效项目 ID；外部 ID 只从本租户投影解析）：

| 路由 | 返回 |
|---|---|
| `GET projects/{id}/sprints?status=DOING&page=1&per_page=20` | `data.sprints[]` + `has_more` + `total` |
| `GET projects/{id}/milestones?status=…` | `data.milestones[]` + `has_more` + `total` |
| `GET projects/{id}/risks` | `data.risks[]`（工作项形状）+ `has_more` + `total` |
| `GET projects/{id}/progress` | `data.total` / `by_category` / `by_status` / `completion` / `overdue_count` / `truncated` / `partial` |

四个只读路由复用能力点 `common.yunxiao.workitem.view`（`yunxiao.view`），不新增权限项。

归一化字段（snake_case，稳定键）：

- 迭代：`external_sprint_id / name / status / status_name / start_date / end_date /
  capacity_hours / locked / description / owners[{id,name}] / creator{id,name} /
  modifier{id,name} / gmt_created / gmt_modified`；
  `status` 保留云效取值 `TODO|DOING|ARCHIVED`，`status_name` 映射为 未开始/进行中/已完成（未知取值回落 null）。
- 里程碑：`external_milestone_id / name / status_id / status_name / status_display_name /
  status_name_en / plan_end_date / actual_end_date / assigned_to / assigned_to_name /
  description / creator / gmt_created / gmt_modified`。
- 风险：与工作项一致，新增 `category`、`due_date` 两个归一化键（`due_date` 兼容顶层与自定义字段）。

**进度聚合语义**（确定性优先，不猜状态含义）：

1. 按 `categories`（默认 `Req,Task,Bug,Risk`）分别翻页 `workitems:search`，逐页累加；
2. `by_status` 按 `status_id`（缺省用 `status_name`）计数，按 count 降序；
3. 完成率只在「已完成状态集合」显式给出时计算：请求 `done_status_ids[]` /
   `done_status_names[]`，或连接配置 `config.progress_done_status_ids` /
   `progress_done_status_names`。都没有时 `completion.defined=false`、`rate=null`，
   **不按状态名猜「完成」**（避免把「已完成待验收」误算为完成）；
4. `overdue_count` 需要同一完成集合 + 工作项带可解析 `due_date`，否则 `null`；
5. 单个 category 失败只标记 `partial=true` 并把原因写进 `errors.{category}`，其余 category 继续；
6. 单类翻页达 `yunxiao.progress.max_pages`（默认 5）仍有下一页时 `truncated=true`，
   计数只代表已读到的下界，下游不得当全量。

请求参数：`categories[]`、`done_status_ids[]`、`done_status_names[]`、`page_size`、`max_pages`。

**外呼样例（依据官方 API 参考；本仓未持真实 token，未做真实外呼实测）**：

```bash
# Region
curl -X GET 'https://{org}.devops.aliyuncs.com/oapi/v1/projex/projects/{projectId}/sprints?status=TODO,DOING&page=1&perPage=20' \
  -H 'x-yunxiao-token: pt-****'
curl -X GET 'https://{org}.devops.aliyuncs.com/oapi/v1/projex/projects/{projectId}/milestones?page=1&perPage=20' \
  -H 'x-yunxiao-token: pt-****'
curl -X POST 'https://{org}.devops.aliyuncs.com/oapi/v1/projex/workitems:search' \
  -H 'x-yunxiao-token: pt-****' -H 'Content-Type: application/json' \
  -d '{"spaceId":"{projectId}","category":"Risk","page":1,"perPage":20}'
```

官方返回样例（节选，`ListSprints`）：

```json
[{"id":"1111","name":"sprint-test","status":"TODO","startDate":"","endDate":"",
  "capacityHours":100,"locked":false,"owners":[{"id":"674d96...","name":"user-name-xxx"}]}]
```

配置：`yunxiao.progress.page_size`（100）/ `yunxiao.progress.max_pages`（5）。
工作项上的 `iteration_id` 已归一化，下游要「按迭代看进度」时按该字段过滤即可
（搜索接口的 sprint 维度过滤未在官方文档中确认，本模块不臆造该参数）。

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
- **未实测边界**：成员写接口按云效中心版 / Region 版 API 参考实现，尚未用真实实例外呼验证；
  请求/响应形状由 `tests/Yunxiao/ProjexYunxiaoClientTest.php` 锁定（含空响应体与 DELETE body，
  以及 Central 的 `organizations/{organizationId}` 前缀）。
  写操作**没有 outbox/幂等键**：远端已成功、本地落盘失败时重试可能重复提交；云效对
  「添加已存在成员」的返回行为未实测，调用方不要把 add 当作严格幂等。

## 需求导入云效（issue #1）

端点 `POST api/v1/yunxiao/projects/{id}/workitems/import`（`{id}` 是 `yunxiao_project_id`），
受能力点 `common.yunxiao.workitem.import`（`yunxiao.manage`）保护。用于把 AI 拆解后的业务需求写入云效需求仓库。

请求体：

```json
{
  "assigned_to": "云效 userId（可被条目覆盖）",
  "workitem_type_id": "云效工作项类型 ID（也可用 workitem_type + 模板映射解析）",
  "priority": "high 或云效优先级字段值 ID",
  "items": [
    {
      "idempotency_key": "需求在宿主侧的业务键",
      "title": "客户需求 A",
      "description": "由立项资料拆解……",
      "assigned_to": "云效 userId",
      "workitem_type_id": "云效工作项类型 ID",
      "priority": "high",
      "status_id": "可选；创建后补写"
    }
  ],
  "dry_run": false,
  "retry_pending": false
}
```

响应逐条给出 `status`（`created` / `replayed` / `failed` / `uncertain` / `dry_run`）、
`external_workitem_id`、`reason`、`error`、`attempts`，顶层汇总 `created` / `replayed` / `failed` /
`uncertain` / `partial`。单条失败不中断整批。

幂等与重试：

- `idempotency_key` 是业务唯一键；缺省用归一化 payload 的 sha256 派生（`auto:<hash>`），同内容重试不会重复建。
- 同键同 payload：`created` 直接 `replayed`；`failed` 同键重试；`pending`（调用远端前落账但未回填，通常是进程崩溃）
  显式返回 `uncertain`，**不自动重试**（远端可能已创建）；确认需要重试时传 `retry_pending=true`。
- 同键异 payload：`idempotency_key_conflict`，不覆盖原请求；修正后的需求请使用新的业务键。
- 创建态先落 `yunxiao_workitem_imports` 账本，再调云效；成功后立即 upsert `yunxiao_workitems` 投影，
  不等下一轮同步。`status_id` 不在云效创建 body 内，创建后 `PUT` 补写；补写失败只记
  `warning=status_update_failed` 与 `metadata.status_pending=true`，重放时补做，不把已创建项标成失败。
- `priority` 必须是云效优先级**字段值 ID**；传业务别名（如 `high`）时需在 `config('yunxiao.import.priority_map')`
  配好映射，未配置的别名原样透传、由云效 422 拒绝（不猜测）。
- `dry_run=true` 只做校验与幂等判定，不外呼、不写账本/投影。

**未实测边界**：按云效官方 CreateWorkitem 文档实现，真实实例的优先级字段值 ID、需求类型 ID、
`assignedTo` 权限与响应形状待下游升级 lock 后现场验收；请求/响应形状由
`tests/Yunxiao/ProjexYunxiaoClientTest.php` 与 `tests/Yunxiao/YunxiaoWorkitemImportTest.php` 锁定。

## 同步与写回

- 工作项同步按连接 + `integration_sync_cursors(resource=workitems)` 增量拉取，限流退避后重试；超过 `stale_minutes` 的投影标记为 stale。
- 写回通过 Integration outbox 入队（`workitem.writeback_status` / `workitem.writeback_comment`），成功 `markSent`、失败 `markFailed`，达到 `max_attempts` 进死信，可经接口重试。
- 状态映射来自模板 `status_map`；无映射或未知状态时只评论不改状态（`comment_only`）。
- 冲突（远端版本已变化）先重新拉取再重试一次，避免覆盖他人修改。

## 数据表

`yunxiao_projects`、`yunxiao_workitems`、`yunxiao_template_maps`、`yunxiao_workitem_imports`（需求导入账本）。连接/凭据、游标、outbox、inbox、同步运行记录均在 Integration。

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
