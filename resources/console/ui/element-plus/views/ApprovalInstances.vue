<template>
  <div class="approval-instances-page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>审批中心</span>
          <el-button :loading="loading" @click="reload">刷新</el-button>
        </div>
      </template>

      <el-tabs v-model="activeTab" @tab-change="onTabChange">
        <el-tab-pane label="全部实例" name="all" />
        <el-tab-pane label="我的待办" name="pending" />
      </el-tabs>

      <div class="stat-row">
        <div class="stat-card">
          <span class="stat-label">待处理</span>
          <span class="stat-value">{{ stats?.pending ?? 0 }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">已通过</span>
          <span class="stat-value">{{ stats?.approved ?? 0 }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">已驳回</span>
          <span class="stat-value">{{ stats?.rejected ?? 0 }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">总量</span>
          <span class="stat-value">{{ stats?.total ?? 0 }}</span>
        </div>
      </div>

      <div class="filter-bar">
        <el-select v-model="filters.status" placeholder="状态" clearable style="width: 140px">
          <el-option
            v-for="item in STATUS_OPTIONS"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
        <el-input
          v-model="filters.definitionKey"
          placeholder="审批定义 key"
          clearable
          style="width: 190px"
          @keyup.enter="search"
        />
        <el-input
          v-model="filters.keyword"
          placeholder="关键词"
          clearable
          style="width: 180px"
          @keyup.enter="search"
        />
        <el-date-picker
          v-model="filters.range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 250px"
        />
        <el-button type="primary" @click="search">查询</el-button>
        <el-button @click="resetFilters">重置</el-button>
      </div>

      <el-table v-loading="loading" :data="rows" border stripe>
        <el-table-column prop="instance_id" label="实例" width="150" />
        <el-table-column
          prop="definition_key"
          label="审批定义"
          min-width="150"
          show-overflow-tooltip
        />
        <el-table-column label="主题" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ subjectTitle(row) }}</template>
        </el-table-column>
        <el-table-column label="项目" width="110">
          <template #default="{ row }">{{ row.project_id ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="当前步骤" width="110">
          <template #default="{ row }">{{ currentStepText(row) }}</template>
        </el-table-column>
        <el-table-column prop="initiated_by" label="发起人" width="110" />
        <el-table-column prop="created_at" label="发起时间" width="170" />
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button
              v-if="canDecide && isActive(row)"
              link
              type="success"
              @click="openDecision(row)"
            >
              决策
            </el-button>
            <el-button
              v-if="canWithdraw && isActive(row)"
              link
              type="warning"
              @click="withdraw(row)"
            >
              撤回
            </el-button>
            <el-button v-if="canAdmin && isActive(row)" link type="danger" @click="cancel(row)">
              取消
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager"
        layout="total, prev, pager, next"
        :total="total"
        :current-page="page"
        :page-size="perPage"
        @current-change="handlePageChange"
      />
    </el-card>

    <el-drawer v-model="detailVisible" title="审批实例详情" size="680px">
      <div v-if="detail" v-loading="detailLoading">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="实例">{{ detail.instance_id }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusTagType(detail.status)">{{ statusLabel(detail.status) }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="审批定义">
            {{ detail.definition_key }} @ v{{ detail.definition_version }}
          </el-descriptions-item>
          <el-descriptions-item label="当前步骤">{{ detail.current_step_no ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="主题" :span="2">{{ subjectTitle(detail) }}</el-descriptions-item>
          <el-descriptions-item label="项目">{{ detail.project_id ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="发起人">{{ detail.initiated_by }}</el-descriptions-item>
          <el-descriptions-item label="发起时间">{{ detail.created_at }}</el-descriptions-item>
          <el-descriptions-item label="完成时间">{{ detail.completed_at ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="结果">{{ detail.outcome ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="豁免">
            {{ detail.is_exempt ? detail.exemption_reason ?? '是' : '否' }}
          </el-descriptions-item>
        </el-descriptions>

        <div class="section-title">步骤</div>
        <el-table :data="detail.steps ?? []" size="small" border>
          <el-table-column prop="step_no" label="#" width="50" />
          <el-table-column prop="name" label="步骤" min-width="120" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag size="small" :type="stepTagType(row.status)">
                {{ stepStatusLabel(row.status) }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="mode" label="模式" width="70" />
          <el-table-column label="审批人" min-width="150">
            <template #default="{ row }">{{ approverText(row) }}</template>
          </el-table-column>
          <el-table-column prop="due_at" label="截止" width="160" />
        </el-table>

        <div class="section-title">时间线</div>
        <el-timeline v-if="timeline.length">
          <el-timeline-item
            v-for="(event, index) in timeline"
            :key="index"
            :timestamp="event.at ?? ''"
            :type="timelineType(event)"
          >
            {{ timelineText(event) }}
          </el-timeline-item>
        </el-timeline>
        <el-empty v-else description="暂无时间线" :image-size="60" />
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
        <el-button
          v-if="canDecide && detail && isActive(detail)"
          type="primary"
          @click="openDecision(detail)"
        >
          决策
        </el-button>
      </template>
    </el-drawer>

    <el-dialog
      v-model="decisionVisible"
      title="审批决策"
      width="520px"
      :close-on-click-modal="false"
    >
      <el-form label-width="70px">
        <el-form-item label="结论">
          <el-radio-group v-model="decisionForm.decision">
            <el-radio value="approve">通过</el-radio>
            <el-radio value="reject">驳回</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="意见">
          <el-input
            v-model="decisionForm.comment"
            type="textarea"
            :rows="4"
            maxlength="1000"
            show-word-limit
            placeholder="驳回时建议填写原因"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="decisionVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitDecision">提交</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@multi-tenant-saas/console/stores/user'
import {
  cancelInstance,
  decideInstance,
  getInstance,
  getInstanceStats,
  getInstanceTimeline,
  listInstances,
  listMyPendingInstances,
  withdrawInstance,
} from '@modules/Approval/api/approval'
import type {
  ApprovalInstance,
  ApprovalInstanceStats,
  ApprovalStep,
  ApprovalTimelineEvent,
} from '@modules/Approval/api/approval'

const STATUS_OPTIONS = [
  { value: 'pending', label: '待处理' },
  { value: 'in_progress', label: '审批中' },
  { value: 'approved', label: '已通过' },
  { value: 'rejected', label: '已驳回' },
  { value: 'withdrawn', label: '已撤回' },
  { value: 'cancelled', label: '已取消' },
  { value: 'expired', label: '已过期' },
]

const STATUS_TEXT: Record<string, string> = Object.fromEntries(
  STATUS_OPTIONS.map((item) => [item.value, item.label]),
)

const STEP_TEXT: Record<string, string> = {
  pending: '待激活',
  active: '进行中',
  approved: '已通过',
  rejected: '已驳回',
  skipped: '已跳过',
  blocked: '已阻塞',
  expired: '已过期',
}

const userStore = useUserStore()
const canDecide = computed(() => userStore.hasPermission('approval.decide'))
const canWithdraw = computed(() => userStore.hasPermission('approval.create'))
const canAdmin = computed(() => userStore.hasPermission('approval.manage'))

const activeTab = ref<'all' | 'pending'>('all')
const loading = ref(false)
const rows = ref<ApprovalInstance[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const stats = ref<ApprovalInstanceStats | null>(null)

const filters = reactive({
  status: '',
  definitionKey: '',
  keyword: '',
  range: null as [string, string] | null,
})

const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<ApprovalInstance | null>(null)
const timeline = ref<ApprovalTimelineEvent[]>([])

const decisionVisible = ref(false)
const submitting = ref(false)
const decisionTarget = ref<ApprovalInstance | null>(null)
const decisionForm = reactive<{ decision: 'approve' | 'reject'; comment: string }>({
  decision: 'approve',
  comment: '',
})

function statusLabel(status: string): string {
  return STATUS_TEXT[status] ?? status
}

function statusTagType(status: string): 'success' | 'warning' | 'danger' | 'info' | 'primary' {
  if (status === 'approved') return 'success'
  if (status === 'rejected') return 'danger'
  if (status === 'pending' || status === 'in_progress') return 'warning'
  if (status === 'withdrawn' || status === 'cancelled') return 'info'
  if (status === 'expired') return 'info'
  return 'primary'
}

function stepStatusLabel(status: string): string {
  return STEP_TEXT[status] ?? status
}

function stepTagType(status: string): 'success' | 'warning' | 'danger' | 'info' {
  if (status === 'approved') return 'success'
  if (status === 'rejected') return 'danger'
  if (status === 'active' || status === 'blocked') return 'warning'
  return 'info'
}

function isActive(instance: ApprovalInstance | null | undefined): boolean {
  return instance?.status === 'pending' || instance?.status === 'in_progress'
}

function subjectTitle(instance: ApprovalInstance): string {
  const snapshot = (instance.subject_snapshot ?? {}) as Record<string, unknown>
  const title = snapshot.title ?? snapshot.name ?? snapshot.subject_title
  if (typeof title === 'string' && title.trim() !== '') return title
  return `${instance.subject_type}#${instance.subject_id}`
}

function currentStepText(instance: ApprovalInstance): string {
  if (instance.current_step_no === null || instance.current_step_no === undefined) return '-'
  const step = (instance.steps ?? []).find(
    (item: ApprovalStep) => item.step_no === instance.current_step_no,
  )
  return step ? `#${step.step_no} ${step.name}` : `#${instance.current_step_no}`
}

function approverText(step: ApprovalStep): string {
  const approvers = (step.approvers_snapshot ?? []) as Array<Record<string, unknown>>
  const names = approvers
    .map((item) => item.name ?? item.label ?? item.operator_id ?? item.id)
    .filter((value): value is string | number => value !== undefined && value !== null)
  return names.length ? names.join('、') : '-'
}

function timelineType(
  event: ApprovalTimelineEvent,
): 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  if (event.type === 'decision') return event.decision === 'reject' ? 'danger' : 'success'
  if (event.type === 'step_blocked') return 'warning'
  if (event.type?.startsWith('instance_rejected')) return 'danger'
  if (event.type?.startsWith('instance_approved')) return 'success'
  return 'primary'
}

function timelineText(event: ApprovalTimelineEvent): string {
  const step = event.step_no ? `步骤 #${event.step_no}${event.name ? ` ${event.name}` : ''}：` : ''
  if (event.type === 'instance_created') return '实例已发起'
  if (event.type === 'step_activated') return `${step}已激活，等待审批`
  if (event.type === 'step_blocked') return `${step}阻塞${event.reason ? `（${event.reason}）` : ''}`
  if (event.type === 'decision') {
    const verdict = event.decision === 'reject' ? '驳回' : '通过'
    return `${step}${verdict}${event.comment ? `：${event.comment}` : ''}`
  }
  if (event.type?.startsWith('instance_')) {
    return `实例${statusLabel(event.type.replace('instance_', ''))}`
  }
  return event.type
}

function buildParams(): Record<string, unknown> {
  const params: Record<string, unknown> = {
    page: page.value,
    per_page: perPage.value,
  }
  if (filters.status) params.status = filters.status
  if (filters.definitionKey) params.definition_key = filters.definitionKey
  if (filters.keyword) params.keyword = filters.keyword
  if (filters.range && filters.range.length === 2) {
    params.from = filters.range[0]
    params.to = filters.range[1]
  }
  return params
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const params = buildParams()
    const result =
      activeTab.value === 'pending'
        ? await listMyPendingInstances({ ...params, page: page.value, perPage: perPage.value })
        : await listInstances({ ...params, page: page.value, perPage: perPage.value })
    rows.value = result.data
    total.value = result.total
  } catch (error) {
    ElMessage.error(errText(error, '加载审批实例失败'))
  } finally {
    loading.value = false
  }
}

async function loadStats(): Promise<void> {
  try {
    stats.value = await getInstanceStats()
  } catch {
    stats.value = null
  }
}

function reload(): void {
  void load()
  void loadStats()
}

function onTabChange(): void {
  page.value = 1
  reload()
}

function search(): void {
  page.value = 1
  void load()
}

function resetFilters(): void {
  filters.status = ''
  filters.definitionKey = ''
  filters.keyword = ''
  filters.range = null
  page.value = 1
  void load()
}

function handlePageChange(next: number): void {
  page.value = next
  void load()
}

async function openDetail(row: ApprovalInstance): Promise<void> {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = row
  timeline.value = []
  try {
    const [instance, events] = await Promise.all([
      getInstance(row.instance_id),
      getInstanceTimeline(row.instance_id),
    ])
    detail.value = instance
    timeline.value = events
  } catch (error) {
    ElMessage.error(errText(error, '加载实例详情失败'))
  } finally {
    detailLoading.value = false
  }
}

function openDecision(row: ApprovalInstance): void {
  decisionTarget.value = row
  decisionForm.decision = 'approve'
  decisionForm.comment = ''
  decisionVisible.value = true
}

async function submitDecision(): Promise<void> {
  const target = decisionTarget.value
  if (!target) return
  if (decisionForm.decision === 'reject' && decisionForm.comment.trim() === '') {
    ElMessage.warning('驳回时请填写意见')
    return
  }
  submitting.value = true
  try {
    await decideInstance(target.instance_id, decisionForm.decision, decisionForm.comment || null)
    ElMessage.success('决策已提交')
    decisionVisible.value = false
    reload()
    if (detailVisible.value && detail.value?.instance_id === target.instance_id) {
      await openDetail(target)
    }
  } catch (error) {
    ElMessage.error(errText(error, '提交决策失败'))
  } finally {
    submitting.value = false
  }
}

async function withdraw(row: ApprovalInstance): Promise<void> {
  try {
    await ElMessageBox.confirm('确认撤回该审批实例？', '撤回审批', { type: 'warning' })
  } catch {
    return
  }
  try {
    await withdrawInstance(row.instance_id)
    ElMessage.success('已撤回')
    reload()
    if (detail.value?.instance_id === row.instance_id) detailVisible.value = false
  } catch (error) {
    ElMessage.error(errText(error, '撤回失败'))
  }
}

async function cancel(row: ApprovalInstance): Promise<void> {
  let reason = ''
  try {
    const result = await ElMessageBox.prompt('请填写取消原因', '取消审批实例', {
      inputPlaceholder: '取消原因',
      inputValidator: (value: string) => (value && value.trim() !== '' ? true : '请填写原因'),
    })
    reason = String(result.value ?? '').trim()
  } catch {
    return
  }
  try {
    await cancelInstance(row.instance_id, reason)
    ElMessage.success('已取消')
    reload()
    if (detail.value?.instance_id === row.instance_id) detailVisible.value = false
  } catch (error) {
    ElMessage.error(errText(error, '取消失败'))
  }
}

function errText(error: unknown, fallback: string): string {
  const response = (error as { response?: { data?: { message?: string } } })?.response
  return response?.data?.message ?? fallback
}

onMounted(() => {
  if (typeof userStore.fetchUser === 'function' && !userStore.isLoggedIn) {
    void userStore.fetchUser()
  }
  reload()
})
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.stat-row {
  display: flex;
  gap: 12px;
  margin: 4px 0 16px;
}

.stat-card {
  flex: 1;
  padding: 12px 16px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-label {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.stat-value {
  font-size: 22px;
  font-weight: 600;
}

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.section-title {
  margin: 18px 0 8px;
  font-weight: 600;
}
</style>
