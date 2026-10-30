import { http, extractListResult } from '@multi-tenant-saas/console/shared/http'

// ========== 类型定义 ==========

export type ApprovalStatus = 'draft' | 'published' | 'archived'

export interface Approval {
  approval_id: number
  title: string
  slug: string | null
  content: string | null
  status: ApprovalStatus
  sort: number
  published_at: string | null
  metadata: Record<string, unknown> | null
  created_at: string
  updated_at: string
}

export interface ApprovalListParams {
  page: number
  pageSize: number
  status?: string
  keyword?: string
}

/** 与后端 index() 的压平响应形状一致：data.data + data.total */
export interface ApprovalListResult {
  data: Approval[]
  total: number
}

export interface SaveApprovalData {
  title: string
  slug?: string | null
  content?: string | null
  status?: ApprovalStatus
  sort?: number
  published_at?: string | null
  metadata?: Record<string, unknown> | null
}

// ========== CRUD ==========

const BASE = '/approval'

export async function getApprovalList(params: ApprovalListParams): Promise<ApprovalListResult> {
  const query: Record<string, unknown> = {
    page: params.page,
    per_page: params.pageSize,
  }
  if (params.status) query.status = params.status
  if (params.keyword) query.keyword = params.keyword

  const res = await http.get<Approval[] | { data: Approval[]; total: number }>(BASE, { params: query })
  return extractListResult(res)
}

export async function createApproval(data: SaveApprovalData): Promise<Approval> {
  const res = await http.post<Approval>(BASE, data)
  return res.data
}

export async function updateApproval(id: number, data: Partial<SaveApprovalData>): Promise<Approval> {
  const res = await http.put<Approval>(`${BASE}/${id}`, data)
  return res.data
}

export async function deleteApproval(id: number): Promise<void> {
  await http.delete(`${BASE}/${id}`)
}

// ========== 审批实例（引擎面） ==========

export type ApprovalInstanceStatus =
  | 'pending'
  | 'in_progress'
  | 'approved'
  | 'rejected'
  | 'withdrawn'
  | 'cancelled'
  | 'expired'

export interface ApprovalStep {
  step_id: number
  instance_id: number
  step_no: number
  name: string
  mode: string
  status: string
  approvers_snapshot: Array<Record<string, unknown>>
  activated_at: string | null
  due_at: string | null
  closed_at: string | null
  blocked_reason: string | null
}

export interface ApprovalDecisionRecord {
  decision_id: number
  instance_id: number
  step_id: number
  operator_id: number
  decision: 'approve' | 'reject' | string
  comment: string | null
  source: string
  decided_at: string | null
  metadata: Record<string, unknown>
}

export interface ApprovalInstance {
  instance_id: number
  definition_id: number
  definition_key: string
  definition_version: number
  subject_type: string
  subject_id: number | string
  project_id: number | null
  subject_snapshot: Record<string, unknown>
  definition_snapshot: Record<string, unknown>
  status: ApprovalInstanceStatus
  current_step_no: number | null
  is_exempt: boolean
  exemption_reason: string | null
  initiated_by: number
  previous_instance_id: number | null
  outcome: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
  updated_at: string
  steps?: ApprovalStep[]
  decisions?: ApprovalDecisionRecord[]
}

export interface ApprovalTimelineEvent {
  type: string
  at: string | null
  step_no?: number | null
  name?: string
  reason?: string | null
  operator_id?: number
  decision?: string
  comment?: string | null
  outcome?: string | null
  is_exempt?: boolean
  exemption_reason?: string | null
}

export interface ApprovalInstanceStats {
  total: number
  pending: number
  approved: number
  rejected: number
  status_counts: Record<string, number>
  [key: string]: unknown
}

export interface ApprovalInstanceListParams {
  page?: number
  perPage?: number
  status?: string
  definition_key?: string
  keyword?: string
  from?: string
  to?: string
  project_id?: number | string
}

export interface ApprovalInstanceListResult {
  data: ApprovalInstance[]
  total: number
}

const INSTANCE_BASE = '/approval/instances'

function instanceQuery(params: ApprovalInstanceListParams): Record<string, unknown> {
  const query: Record<string, unknown> = {
    page: params.page ?? 1,
    per_page: params.perPage ?? 20,
  }
  if (params.status) query.status = params.status
  if (params.definition_key) query.definition_key = params.definition_key
  if (params.keyword) query.keyword = params.keyword
  if (params.from) query.from = params.from
  if (params.to) query.to = params.to
  if (params.project_id !== undefined && params.project_id !== null) {
    query.project_id = params.project_id
  }
  return query
}

function unwrapList(
  payload: { data?: ApprovalInstance[]; total?: number } | undefined,
): ApprovalInstanceListResult {
  return {
    data: Array.isArray(payload?.data) ? payload.data : [],
    total: typeof payload?.total === 'number' ? payload.total : 0,
  }
}

export async function listInstances(
  params: ApprovalInstanceListParams,
): Promise<ApprovalInstanceListResult> {
  const res = await http.get<{ data?: ApprovalInstance[]; total?: number }>(INSTANCE_BASE, {
    params: instanceQuery(params),
  })
  return unwrapList(res.data)
}

export async function listMyPendingInstances(
  params: ApprovalInstanceListParams,
): Promise<ApprovalInstanceListResult> {
  const res = await http.get<{ data?: ApprovalInstance[]; total?: number }>(
    `${INSTANCE_BASE}/my-pending`,
    { params: instanceQuery(params) },
  )
  return unwrapList(res.data)
}

export async function getInstanceStats(): Promise<ApprovalInstanceStats> {
  const res = await http.get<ApprovalInstanceStats>(`${INSTANCE_BASE}/stats`)
  return res.data
}

export async function getInstance(id: number | string): Promise<ApprovalInstance> {
  const res = await http.get<ApprovalInstance>(`${INSTANCE_BASE}/${id}`)
  return res.data
}

export async function getInstanceTimeline(id: number | string): Promise<ApprovalTimelineEvent[]> {
  const res = await http.get<ApprovalTimelineEvent[]>(`${INSTANCE_BASE}/${id}/timeline`)
  return Array.isArray(res.data) ? res.data : []
}

export async function decideInstance(
  id: number | string,
  decision: 'approve' | 'reject',
  comment?: string | null,
): Promise<ApprovalDecisionRecord> {
  const res = await http.post<ApprovalDecisionRecord>(`${INSTANCE_BASE}/${id}/decisions`, {
    decision,
    comment: comment ?? null,
  })
  return res.data
}

export async function withdrawInstance(id: number | string): Promise<ApprovalInstance> {
  const res = await http.post<ApprovalInstance>(`${INSTANCE_BASE}/${id}/withdraw`)
  return res.data
}

export async function cancelInstance(
  id: number | string,
  reason: string,
): Promise<ApprovalInstance> {
  const res = await http.post<ApprovalInstance>(`${INSTANCE_BASE}/${id}/cancel`, { reason })
  return res.data
}
