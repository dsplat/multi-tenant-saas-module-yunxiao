<template>
  <div class="yunxiao-page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>云效集成</span>
          <div class="header-actions">
            <el-button :loading="loading" @click="loadActive">刷新</el-button>
            <el-button type="primary" @click="projectDialogVisible = true">H1 建项目</el-button>
          </div>
        </div>
      </template>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="连接 token 只保存在服务端 Integration 凭据中；工作项为只读投影，修改请前往云效。"
        class="notice"
      />

      <el-tabs v-model="activeTab" @tab-change="loadActive">
        <el-tab-pane label="连接" name="connections">
          <el-table v-loading="loading" :data="connections" border stripe>
            <el-table-column prop="name" label="名称" min-width="160" show-overflow-tooltip />
            <el-table-column prop="org_id" label="组织" width="140" show-overflow-tooltip />
            <el-table-column prop="region" label="Region" width="120" />
            <el-table-column label="Token" width="130">
              <template #default="{ row }">
                <el-tag :type="row.has_token ? 'success' : 'danger'">
                  {{ row.has_token ? '已配置' : '缺失' }}
                </el-tag>
                <el-tag v-if="row.token_expired" type="danger" class="tag-gap">已过期</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="写回" width="90">
              <template #default="{ row }">
                <el-tag :type="row.writeback_enabled ? 'success' : 'info'">
                  {{ row.writeback_enabled ? '开启' : '关闭' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="last_checked_at" label="最近检查" width="170" />
            <el-table-column prop="last_check_error" label="检查错误" min-width="200" show-overflow-tooltip />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="项目" name="projects">
          <el-table v-loading="loading" :data="projects" border stripe>
            <el-table-column prop="project_id" label="宿主项目" width="110" />
            <el-table-column prop="name" label="项目名称" min-width="180" show-overflow-tooltip />
            <el-table-column prop="custom_code" label="customCode" width="120" />
            <el-table-column prop="external_project_id" label="云效项目 ID" width="150" show-overflow-tooltip />
            <el-table-column prop="template_id" label="模板" width="130" show-overflow-tooltip />
            <el-table-column label="状态" width="120">
              <template #default="{ row }">
                <el-tag :type="projectTagType(row.status)">{{ projectStatusLabel(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="last_synced_at" label="最近同步" width="170" />
            <el-table-column label="操作" width="130" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" :loading="syncing" @click="syncProject(row)">同步工作项</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="工作项" name="workitems">
          <div class="filter-bar">
            <el-input
              v-model="workitemFilters.serial_number"
              placeholder="任务号（如 TASK-123）"
              clearable
              style="width: 220px"
              @keyup.enter="loadWorkitems"
            />
            <el-input
              v-model="workitemFilters.status_id"
              placeholder="云效状态 ID"
              clearable
              style="width: 180px"
              @keyup.enter="loadWorkitems"
            />
            <el-button type="primary" @click="loadWorkitems">查询</el-button>
          </div>

          <el-table v-loading="loading" :data="workitems" border stripe>
            <el-table-column prop="serial_number" label="任务号" width="140" />
            <el-table-column prop="subject" label="标题" min-width="220" show-overflow-tooltip />
            <el-table-column prop="workitem_type_id" label="类型" width="110" />
            <el-table-column label="状态" width="150">
              <template #default="{ row }">
                {{ row.status_name || row.status_id || '-' }}
                <el-tag v-if="row.unmapped" type="warning" class="tag-gap">未映射</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="assigned_to" label="负责人" width="130" show-overflow-tooltip />
            <el-table-column prop="gmt_modified" label="更新时间" width="170" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="写回" name="writebacks">
          <el-table v-loading="loading" :data="writebacks" border stripe>
            <el-table-column prop="message_id" label="ID" width="90" />
            <el-table-column prop="serial_number" label="任务号" width="140" />
            <el-table-column prop="action" label="动作" min-width="200" show-overflow-tooltip />
            <el-table-column label="状态" width="110">
              <template #default="{ row }">
                <el-tag :type="writebackTagType(row.status)">{{ writebackStatusLabel(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="attempts" label="尝试" width="80" />
            <el-table-column prop="last_error" label="错误" min-width="200" show-overflow-tooltip />
            <el-table-column label="操作" width="110" fixed="right">
              <template #default="{ row }">
                <el-button
                  v-if="row.status === 'failed' || row.status === 'dead'"
                  link
                  type="primary"
                  @click="retryWriteback(row)"
                >
                  重试
                </el-button>
                <span v-else>-</span>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog v-model="projectDialogVisible" title="H1 创建云效项目" width="520px">
      <el-form :model="projectForm" label-width="110px">
        <el-form-item label="宿主项目 ID" required>
          <el-input-number v-model="projectForm.project_id" :min="1" />
        </el-form-item>
        <el-form-item label="项目名称" required>
          <el-input v-model="projectForm.name" maxlength="255" />
        </el-form-item>
        <el-form-item label="宿主项目类型" required>
          <el-input v-model="projectForm.project_type" placeholder="如 nwu（对应模板映射）" />
        </el-form-item>
        <el-form-item label="customCode">
          <el-input v-model="projectForm.custom_code" placeholder="4–6 位大写字母；留空自动生成" />
        </el-form-item>
        <el-form-item label="项目经理">
          <el-input v-model="projectForm.project_manager" placeholder="云效用户 ID（可选）" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="projectDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="createProject">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  createYunxiaoProject,
  getYunxiaoConnections,
  getYunxiaoProjects,
  getYunxiaoWorkitems,
  getYunxiaoWritebacks,
  retryYunxiaoWriteback,
  syncYunxiaoWorkitems,
} from '@modules/Yunxiao/api/yunxiao'
import type {
  YunxiaoConnection,
  YunxiaoProject,
  YunxiaoWorkitem,
  YunxiaoWriteback,
} from '@modules/Yunxiao/api/yunxiao'

const PROJECT_STATUS_TEXT: Record<string, string> = {
  active: '正常',
  partial: '部分成功',
  creation_failed: '创建失败',
}

const WRITEBACK_STATUS_TEXT: Record<string, string> = {
  pending: '待投递',
  sending: '投递中',
  sent: '已发送',
  failed: '失败待重试',
  dead: '死信',
}

const activeTab = ref('connections')
const loading = ref(false)
const saving = ref(false)
const syncing = ref(false)

const connections = ref<YunxiaoConnection[]>([])
const projects = ref<YunxiaoProject[]>([])
const workitems = ref<YunxiaoWorkitem[]>([])
const writebacks = ref<YunxiaoWriteback[]>([])

const workitemFilters = reactive({ serial_number: '', status_id: '' })

const projectDialogVisible = ref(false)
const projectForm = reactive({
  project_id: undefined as number | undefined,
  name: '',
  project_type: '',
  custom_code: '',
  project_manager: '',
})

function projectStatusLabel(status: string): string {
  return PROJECT_STATUS_TEXT[status] ?? status
}

function projectTagType(status: string): 'success' | 'warning' | 'danger' {
  if (status === 'active') return 'success'
  if (status === 'partial') return 'warning'
  return 'danger'
}

function writebackStatusLabel(status: string): string {
  return WRITEBACK_STATUS_TEXT[status] ?? status
}

function writebackTagType(status: string): 'info' | 'warning' | 'success' | 'danger' {
  if (status === 'sent') return 'success'
  if (status === 'failed') return 'warning'
  if (status === 'dead') return 'danger'
  return 'info'
}

async function loadConnections(): Promise<void> {
  connections.value = (await getYunxiaoConnections()).data
}

async function loadProjects(): Promise<void> {
  projects.value = (await getYunxiaoProjects({ per_page: 50 })).data
}

async function loadWorkitems(): Promise<void> {
  workitems.value = (
    await getYunxiaoWorkitems({
      per_page: 50,
      serial_number: workitemFilters.serial_number || undefined,
      status_id: workitemFilters.status_id || undefined,
    })
  ).data
}

async function loadWritebacks(): Promise<void> {
  writebacks.value = (await getYunxiaoWritebacks({ per_page: 50 })).data
}

async function loadActive(): Promise<void> {
  loading.value = true
  try {
    if (activeTab.value === 'connections') await loadConnections()
    if (activeTab.value === 'projects') await loadProjects()
    if (activeTab.value === 'workitems') await loadWorkitems()
    if (activeTab.value === 'writebacks') await loadWritebacks()
  } catch {
    ElMessage.error('加载云效数据失败（请检查连接与权限）')
  } finally {
    loading.value = false
  }
}

async function createProject(): Promise<void> {
  if (!projectForm.project_id || !projectForm.name || !projectForm.project_type) {
    ElMessage.warning('宿主项目 ID、名称与项目类型必填')
    return
  }

  saving.value = true
  try {
    const result = await createYunxiaoProject({
      project_id: projectForm.project_id,
      name: projectForm.name,
      project_type: projectForm.project_type,
      custom_code: projectForm.custom_code || undefined,
      project_manager: projectForm.project_manager || undefined,
    })
    ElMessage.success(result.created ? '云效项目已创建' : '项目已存在，已返回既有投影')
    projectDialogVisible.value = false
    activeTab.value = 'projects'
    await loadProjects()
  } catch {
    ElMessage.error('创建云效项目失败（检查模板映射与 customCode 冲突）')
  } finally {
    saving.value = false
  }
}

async function syncProject(row: YunxiaoProject): Promise<void> {
  syncing.value = true
  try {
    const stats = await syncYunxiaoWorkitems({ project_id: row.project_id })
    ElMessage[stats.partial ? 'warning' : 'success'](
      `同步完成：抓取 ${stats.fetched}，新增 ${stats.created}，更新 ${stats.updated}`,
    )
    activeTab.value = 'workitems'
    await loadWorkitems()
  } catch {
    ElMessage.error('同步工作项失败')
  } finally {
    syncing.value = false
  }
}

async function retryWriteback(row: YunxiaoWriteback): Promise<void> {
  try {
    await retryYunxiaoWriteback(row.message_id)
    ElMessage.success('已重试写回')
    await loadWritebacks()
  } catch {
    ElMessage.error('重试失败（仅 failed/dead 可重试）')
  }
}

onMounted(loadActive)
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-actions {
  display: flex;
  gap: 8px;
}

.notice {
  margin-bottom: 12px;
}

.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 12px;
}

.tag-gap {
  margin-left: 6px;
}
</style>
