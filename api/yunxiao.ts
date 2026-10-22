import { http, extractListResult } from '@multi-tenant-saas/console/shared/http'

// ========== 类型定义 ==========

export type YunxiaoConnectionStatus = 'active' | 'disabled'
export type YunxiaoProjectStatus = 'active' | 'partial' | 'creation_failed'
export type YunxiaoWritebackStatus = 'pending' | 'sending' | 'sent' | 'failed' | 'dead'

/** 连接脱敏视图：后端永不返回 credentials / token */
export interface YunxiaoConnection {
  connection_id: number
  name: string
  provider: string
  base_url: string
  auth_type: string
  status: YunxiaoConnectionStatus
  has_token: boolean
  token_expires_at: string | null
  token_expired: boolean
  token_expiring_soon: boolean
  scope: string
  org_id: string | null
  org_name: string | null
  region: string | null
  writeback_enabled: boolean
  default_template_id: string | null
  timeout: number
  rate_limit: number
  last_checked_at: string | null
  last_check_error: string | null
  created_at: string
  updated_at: string
}

export interface YunxiaoConnectionPayload {
  name: string
  base_url?: string
  token?: string
  auth_type?: string
  scope?: 'Region'
  org_id?: string | null
  region?: string | null
  writeback_enabled?: boolean
  token_expires_at?: string | null
  timeout?: number
  default_template_id?: string | null
}

export interface YunxiaoConnectionTestResult {
  ok: boolean
  projects: number
  templates: number
  error: string | null
}

export interface YunxiaoProject {
  yunxiao_project_id: number
  connection_id: number
  project_id: number
  external_project_id: string
  name: string
  custom_code: string
  template_id: string
  scope: string | null
  status: YunxiaoProjectStatus
  last_synced_at: string | null
  mapping_pending: boolean
  assignment_failures: string[]
  metadata: Record<string, unknown>
  created_at: string
  updated_at: string
}

export interface YunxiaoProjectPayload {
  project_id: number
  name: string
  project_type: string
  custom_code?: string | null
  scope?: string | null
  description?: string | null
  project_manager?: string | null
  connection_id?: number | null
}

export interface YunxiaoProjectCreateResult {
  project: YunxiaoProject
  created: boolean
  mapping_status: 'ok' | 'pending'
  assignment_failures: string[]
}

export interface YunxiaoWorkitem {
  yunxiao_workitem_id: number
  connection_id: number
  yunxiao_project_id: number
  external_workitem_id: string
  serial_number: string
  workitem_type_id: string | null
  subject: string
  status_id: string | null
  status_name: string | null
  assigned_to: string | null
  gmt_created: string | null
  gmt_modified: string | null
  fetched_at: string | null
  unmapped: boolean
  metadata: Record<string, unknown>
}

export interface YunxiaoWorkitemDetail {
  workitem: YunxiaoWorkitem
  stale: boolean
  error: string | null
}

export interface YunxiaoSyncStats {
  tenant_id: number
  connection_id: number
  resource: string
  dry_run: boolean
  projects: number
  pages: number
  fetched: number
  created: number
  updated: number
  failed: number
  partial: boolean
  errors: string[]
  cursor: string | null
}

export interface YunxiaoWriteback {
  message_id: number
  connection_id: number
  action: string
  status: YunxiaoWritebackStatus
  attempts: number
  available_at: string | null
  last_error: string | null
  external_ref: string | null
  sent_at: string | null
  workitem_id: number | null
  external_workitem_id: string | null
  serial_number: string | null
  status_id: string | null
  comment: string | null
  gitlab: Record<string, string> | null
  created_at: string
  updated_at: string
}

export interface YunxiaoTemplateMap {
  template_map_id: number
  project_type: string
  template_id: string
  workitem_type_map: Record<string, string>
  status_map: Record<string, string>
  is_default: boolean
  metadata: Record<string, unknown>
  created_at: string
  updated_at: string
}

export interface YunxiaoListParams {
  page?: number
  per_page?: number
  [key: string]: string | number | boolean | undefined
}

export interface YunxiaoListResult<T> {
  data: T[]
  total: number
}

// ========== 连接 ==========

const BASE = '/yunxiao'

export async function getYunxiaoConnections(): Promise<YunxiaoListResult<YunxiaoConnection>> {
  const res = await http.get<YunxiaoConnection[] | { data: YunxiaoConnection[]; total: number }>(
    `${BASE}/connections`,
  )
  return extractListResult(res)
}

export async function createYunxiaoConnection(
  payload: YunxiaoConnectionPayload,
): Promise<YunxiaoConnection> {
  const res = await http.post<YunxiaoConnection>(`${BASE}/connections`, payload)
  return res.data
}

export async function updateYunxiaoConnection(
  id: number,
  payload: Partial<YunxiaoConnectionPayload>,
): Promise<YunxiaoConnection> {
  const res = await http.put<YunxiaoConnection>(`${BASE}/connections/${id}`, payload)
  return res.data
}

export async function testYunxiaoConnection(id: number): Promise<YunxiaoConnectionTestResult> {
  const res = await http.post<YunxiaoConnectionTestResult>(`${BASE}/connections/${id}/test`)
  return res.data
}

export async function rotateYunxiaoToken(
  id: number,
  token: string,
  tokenExpiresAt?: string,
): Promise<YunxiaoConnection> {
  const res = await http.post<YunxiaoConnection>(`${BASE}/connections/${id}/rotate`, {
    token,
    token_expires_at: tokenExpiresAt,
  })
  return res.data
}

// ========== 项目（H1 投影） ==========

export async function getYunxiaoProjects(
  params: YunxiaoListParams = {},
): Promise<YunxiaoListResult<YunxiaoProject>> {
  const res = await http.get<YunxiaoProject[] | { data: YunxiaoProject[]; total: number }>(
    `${BASE}/projects`,
    { params },
  )
  return extractListResult(res)
}

export async function createYunxiaoProject(
  payload: YunxiaoProjectPayload,
): Promise<YunxiaoProjectCreateResult> {
  const res = await http.post<YunxiaoProjectCreateResult>(`${BASE}/projects`, payload)
  return res.data
}

// ========== 工作项（只读投影） ==========

export async function getYunxiaoWorkitems(
  params: YunxiaoListParams = {},
): Promise<YunxiaoListResult<YunxiaoWorkitem>> {
  const res = await http.get<YunxiaoWorkitem[] | { data: YunxiaoWorkitem[]; total: number }>(
    `${BASE}/workitems`,
    { params },
  )
  return extractListResult(res)
}

export async function getYunxiaoWorkitem(
  id: number,
  refresh = true,
): Promise<YunxiaoWorkitemDetail> {
  const res = await http.get<YunxiaoWorkitemDetail>(`${BASE}/workitems/${id}`, {
    params: { refresh },
  })
  return res.data
}

export async function syncYunxiaoWorkitems(payload: {
  project_id?: number
  yunxiao_project_id?: number
  connection_id?: number
  dry_run?: boolean
}): Promise<YunxiaoSyncStats> {
  const res = await http.post<YunxiaoSyncStats>(`${BASE}/sync`, payload)
  return res.data
}

// ========== 写回记录 ==========

export async function getYunxiaoWritebacks(
  params: YunxiaoListParams = {},
): Promise<YunxiaoListResult<YunxiaoWriteback>> {
  const res = await http.get<YunxiaoWriteback[] | { data: YunxiaoWriteback[]; total: number }>(
    `${BASE}/writebacks`,
    { params },
  )
  return extractListResult(res)
}

export async function retryYunxiaoWriteback(
  id: number,
): Promise<{ writeback: YunxiaoWriteback }> {
  const res = await http.post<{ writeback: YunxiaoWriteback }>(`${BASE}/writebacks/${id}/retry`)
  return res.data
}

// ========== 模板 / 状态映射 ==========

export async function getYunxiaoTemplateMaps(): Promise<YunxiaoListResult<YunxiaoTemplateMap>> {
  const res = await http.get<YunxiaoTemplateMap[] | { data: YunxiaoTemplateMap[]; total: number }>(
    `${BASE}/template-maps`,
  )
  return extractListResult(res)
}

export async function saveYunxiaoTemplateMap(
  payload: Omit<YunxiaoTemplateMap, 'template_map_id' | 'metadata' | 'created_at' | 'updated_at'>,
  id?: number,
): Promise<YunxiaoTemplateMap> {
  const res = id
    ? await http.put<YunxiaoTemplateMap>(`${BASE}/template-maps/${id}`, payload)
    : await http.post<YunxiaoTemplateMap>(`${BASE}/template-maps`, payload)
  return res.data
}
