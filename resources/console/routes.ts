import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 审批中心
  {
    path: 'approval',
    name: 'ApprovalManagement',
    component: view('Approval', 'ApprovalManagement'),
    meta: { title: '审批中心', permission: 'approval.view' },
  },
]

export default routes
