云效集成（Region OpenAPI）
===============

模块标识：`yunxiao`
后台入口：Console → 云效集成（路由 `yunxiao`）
数据表：`yunxiao_projects`、`yunxiao_workitems`、`yunxiao_template_maps`
连接/映射/游标/写回：复用 Integration 标准表（`integration_connections`、`external_mappings`、
`integration_sync_cursors`、`integration_sync_runs`、`integration_outbox_messages`）。

## 使用顺序

1. **录入连接**：`POST /api/v1/yunxiao/connections`，填写名称、Region 地址与 token。
   保存前会先列项目 + 模板验证 token/组织/权限，失败不落库；token 只进 Integration 加密凭据。
2. **配置模板映射**：`POST /api/v1/yunxiao/template-maps`，按宿主项目类型填 `template_id`、
   `workitem_type_map`、`status_map`，可选 `is_default`。映射保存时校验模板/类型/状态必须来自云效真实工作流。
3. **H1 建项目**：`POST /api/v1/yunxiao/projects`，以宿主 `project_id` 幂等；`custom_code`
   4–6 位大写字母，留空则稳定生成，冲突显式报错不静默换码。PM/成员缺失会标记 `partial`，可重试。
4. **同步工作项**：`POST /api/v1/yunxiao/sync` 或命令 `yunxiao:sync-workitems`。
   按项目增量（Integration 游标 `resource=workitems`），分页 + 限流退避，部分页失败不回滚成功页。
5. **查看投影与写回**：`GET /api/v1/yunxiao/workitems`、`GET /api/v1/yunxiao/writebacks`。
   工作项只读；未知状态原样保存并标 `unmapped`。详情实时拉取失败时回退缓存并标 `stale`。

## 写回规则

- 由宿主/GitLab 事件提供输入，调用 Yunxiao 写回服务；本模块不依赖 Gitlab 模块。
- 按任务号 `serialNumber` 解析工作项：`external_mappings`（`source_type=workitem`）优先，
  回落 `yunxiao_workitems.serial_number`；未匹配正常返回，不报错、不入队。
- `status_map` 有映射且状态属于真实工作流才改状态；无映射/未知状态只评论。
- 入队 `integration_outbox_messages`，幂等键 `gitlab:{project}:{mr|pipeline}:{object}:{action}`；
  失败指数退避，超过上限转死信，可 `POST /api/v1/yunxiao/writebacks/{id}/retry` 或命令重试。
- 连接 `writeback_enabled=false` 或 token 过期时拒绝写回；投影仍可只读访问。

## 能力点（RBAC）

| 能力点 | 权限 | 说明 |
|------|------|------|
| `common.yunxiao.connection.view` | `yunxiao.view` | 查看脱敏连接 |
| `common.yunxiao.connection.manage` | `yunxiao.manage` | 创建/更新/测试/轮换连接 |
| `common.yunxiao.project.view` | `yunxiao.view` | 项目投影 |
| `common.yunxiao.project.create` | `yunxiao.manage` | H1 建项目 |
| `common.yunxiao.workitem.view` | `yunxiao.view` | 工作项投影 |
| `common.yunxiao.workitem.sync` | `yunxiao.manage` | 手动同步 |
| `common.yunxiao.writeback.view` | `yunxiao.view` | 写回记录 |
| `common.yunxiao.writeback.retry` | `yunxiao.manage` | 重试/死信 |
| `common.yunxiao.template_map.manage` | `yunxiao.manage` | 模板/状态映射 |
| `common.yunxiao.member.sync` | `yunxiao.manage` | 成员同步（P1，当前无端点） |

兼容别名：`list/read` → `yunxiao.view`；`create/update/delete` → `yunxiao.manage`。

## API

前缀 `api/v1/yunxiao`，认证 `auth:sanctum`，租户由 `tenant.identify` 解析，逐路由 `capability` 授权。

| 方法 | 路径 | 能力点 |
|------|------|------|
| GET/POST | `connections` | connection.view / manage |
| PUT | `connections/{id}` | connection.manage |
| POST | `connections/{id}/test`、`connections/{id}/rotate` | connection.manage |
| GET/POST | `projects` | project.view / create |
| GET | `workitems`、`workitems/{id}` | workitem.view |
| POST | `sync` | workitem.sync |
| GET | `writebacks` | writeback.view |
| POST | `writebacks/{id}/retry` | writeback.retry |
| GET/POST/PUT | `template-maps` | template_map.manage |

## 命令

```bash
php artisan yunxiao:sync-workitems --tenant=1 [--project=9001] [--dry-run]
php artisan yunxiao:retry-writebacks --tenant=1 [--dry-run]
php artisan yunxiao:verify-template --tenant=1 [--dry-run]
```

## AI 工具（L1，只读/起草）

- `yunxiao_workitem_query`：按任务号/项目/状态查询投影；
- `yunxiao_progress_summary`：状态数量与未映射聚合，不含成员隐私；
- `yunxiao_writeback_comment_draft`：只生成评论草稿，不入队、不提交。

## 安全边界

- token 只在 `integration_connections.credentials`（`encrypted:array`），API 只返回
  `has_token`/`token_expires_at`/`scope`/`org_id`/`region`/`writeback_enabled`/检查结果；
- base_url 保存前过 SSRF 守卫：默认仅 https + `devops.aliyuncs.com`，拒绝 localhost/私网/元数据地址；
- 日志/异常/写回错误摘要均脱敏；不建 `yunxiao_connections`、`yunxiao_sync_cursors`、
  `yunxiao_workitem_mappings`；
- 不提供工作项编辑、实际工时写入或构建触发。

## P1 / 未实现

- 真实云效 Region 联调（opt-in 测试，需专用测试项目）；
- 成员同步端点（能力点已注册，当前通过 PM/成员指派核验降级为 partial）；
- 真实 Region 配额自动调节（当前仅有超时/分页/限流退避配置）。
