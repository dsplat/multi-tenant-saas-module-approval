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
