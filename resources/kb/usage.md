审批中心
===============

模块标识：approval
后台入口：Console → 审批中心（路由 `approval`，实例中心）；旧台账在 `approval-ledger`
数据表：`approvals`

## 能力

- 审批实例：全部实例 / 我的待办、状态与关键词筛选、统计卡；详情含步骤与时间线；
  通过/驳回决策（`common.approval.decision.submit`），发起人撤回、管理员取消；
  旧 `approvals` 台账保留在独立入口。
- 列表查询：按状态与标题关键词筛选，分页返回
- 新建 / 编辑：标题、slug、状态、内容、排序
- 删除：软删除，可由 `deleted_at` 恢复

## RBAC 权限

| 权限 | 说明 |
|------|------|
| `approval.view` | 查看列表与详情 |
| `approval.create` | 新建 |
| `approval.update` | 编辑 |
| `approval.delete` | 删除 |

权限由模块迁移 `Database/migrations/` 中随建表一并注册，随模块卸载回收。

## API

前缀 `api/v1`，认证 `auth:sanctum`，租户由 `tenant.identify` 解析。

| 方法 | 路径 | 权限 |
|------|------|------|
| GET | `approval` | approval.view |
| GET | `approval/{id}` | approval.view |
| POST | `approval` | approval.create |
| PUT | `approval/{id}` | approval.update |
| DELETE | `approval/{id}` | approval.delete |

## 皮肤点与接线契约

本模块视图（`resources/console/`）定位为**参考皮肤**：直用默认皮 + 可运行文档，
项目可经下游 routes.ts 自指视图顶替；内核 API（上节）是唯一契约。

| 皮肤点 | 机器 | 覆盖方式 |
|------|------|------|
| 场景入口 | `registerScene('approval', …)` | 下游同 slug 再注册覆盖 title/icon/priority（last-wins） |
| 直达卡片 | `registerCard(…)`（按需；fallback 用 router 根路径，不带 /console 前缀） | 下游同 id 覆盖文案 / fallback |
| AI 工具 | `register`（私有）/ `registerDefault`（可换皮） | 下游同名 `register` 特化接管 |
| 文案策略 | Config 白名单（如有 EDITABLE 键，在此列出） | tenant_settings 租户级覆写，零部署 |
| 视图 | 下游 routes.ts 自指视图复用本模块 API | 内核零改动 |

契约详情见框架 `docs/2026-09-29-module-group-contract-and-scene-registry.md`。

## 待办

- [ ] 按业务补充字段与状态机（当前为脚手架默认的 title/slug/content/status）
- [ ] 按需接入 AI 工具（`Services/Tools/*Handler.php` + ServiceProvider 中 ToolRegistry 注册）
- [ ] 按需补充前台渲染 / SEO 直出链路
