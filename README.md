# Approval 审批中心

可版本化的审批定义 + 实例 / 步骤 / 决策账本。宿主以 `subject_type` + `subject_id` 挂接任意业务对象，模块不内置具体业务流程。

## 分层与依赖

- 架构层：`common`（`config/module-architecture.php`）
- 依赖模块：无
- 冲突声明：无
- 迁移：`2025_01_03_000016_approval_module.php`（4 张表）、`2025_01_04_000016_approval_module_data.php`（RBAC 权限，幂等）

## 配置 `config/approval.php`

| 键 | 默认 | 说明 |
|---|---|---|
| `enabled` | `true` | 模块开关（`module.enabled` 中间件读取） |
| `route_prefix` | `''` | 路由额外前缀，默认挂在 `api/v1` |
| `per_page` | `20` | 列表默认分页 |
| `max_sla_hours` | `8760` | 单步骤 SLA 上限 |
| `comment_max_length` | `2000` | 决策意见长度上限 |
| `export_max_rows` | `1000` | 同步导出最大行数 |

## 路由（`api/v1`）

- `approval/definitions`：列表、创建、详情、更新、发布、归档、创建新版本
- `approval/instances`：列表、`my-pending`、`stats`、`export`、发起、详情、时间线、撤回、取消、提交决策

静态路径（`my-pending` / `stats` / `export`）注册在 `{id}` 之前，避免被实例 ID 吞掉。

## 能力点

默认权限集：`approval.view` / `approval.manage` / `approval.create` / `approval.decide` / `approval.export`。

- `common.approval.definition.view` / `definition.manage`
- `common.approval.instance.view` / `instance.create` / `instance.withdraw`
- `common.approval.decision.submit`
- `common.approval.admin` / `export`
- 兼容别名（保留一个周期）：`list` / `read` → `instance.view`；`create` → `instance.create`；`update` / `delete` → 管理权限

## 数据表

`approval_definitions`、`approval_instances`、`approval_steps`、`approval_decisions`。实例保存定义与主体的快照，定义后续改版不影响在途实例。

## 审批人解析

内置 `fixed` / `role` / `subject_owner` 三种解析器，宿主可向 `ApproverResolverRegistry` 注册同 key 实现覆盖（last-wins）。审批结果可监听模块事件回写业务对象。

## 安全

- 仅 `Operator` 身份可调用；能力点按租户授权，未知资源默认 fail-closed。
- 资源 `approval_definitions` / `approval_instances` / `approval_steps` / `approval_decisions` 均声明为敏感、需策略。
- 决策意见、豁免原因等敏感字段先经资源列裁剪再进入工具/导出。

## 定时任务

`approval:expire` 扫描超时实例并按配置处理；由部署方调度，不内嵌调度表。

## 测试

```bash
php artisan test --parallel --processes=2 tests/Approval
```
