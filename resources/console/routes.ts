import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 审批中心：审批实例列表 / 详情 / 决策（引擎面）
  {
    path: 'approval',
    name: 'ApprovalInstances',
    component: view('Approval', 'ApprovalInstances'),
    meta: { title: '审批中心', permission: 'approval.view' },
  },
  // 旧 approvals 台账，保留入口避免既有数据失联
  {
    path: 'approval-ledger',
    name: 'ApprovalManagement',
    component: view('Approval', 'ApprovalManagement'),
    meta: { title: '审批台账（旧）', permission: 'approval.view' },
  },
]

export default routes
