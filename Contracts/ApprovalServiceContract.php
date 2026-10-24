<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDefinition;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 审批实例服务契约
 *
 * 只覆盖实例生命周期（发起 / 决策 / 撤回 / 取消 / 豁免 / 到期）。
 * 定义维护见 ApprovalDefinitionService，查询见 ApprovalQueryService。
 *
 * actor 语义（C2O 采用约定 v0.4 §4.1）：actor 由可信执行上下文（认证身份 /
 * 受信服务上下文）提供；业务模块内部调用也必须证明主体操作权（User 发起要求
 * 主体契约 ApprovalUserSubjectContract 裁决）；请求体自报身份一律忽略。
 * 存量 `initiated_by` 无类型列按 operator 语义解释（安全默认），兼容入口
 * initiate() / withdraw() 保留不动。
 */
interface ApprovalServiceContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function initiate(int $tenantId, int $operatorId, array $data): ApprovalInstance;

    /**
     * actor-aware 发起：tenant 取 actor 执行上下文；发起人落 (initiated_by_type, initiated_by)。
     *
     * @param  array<string, mixed>  $data
     */
    public function initiateAs(ExecutionIdentity $actor, array $data): ApprovalInstance;

    /**
     * 记录豁免（生成 approved + is_exempt=true 的账本实例，不解析审批人）。
     *
     * @param  array<string, mixed>  $data
     */
    public function recordExemption(int $tenantId, int $operatorId, array $data): ApprovalInstance;

    /**
     * @param  array<string, mixed>  $data
     */
    public function decide(int $tenantId, int $operatorId, int|string $instanceId, array $data): ApprovalDecision;

    public function withdraw(int $tenantId, int $operatorId, int|string $instanceId): ApprovalInstance;

    /**
     * actor-aware 撤回：发起人判定为 (initiated_by_type, initiated_by) 双匹配，
     * 同数值 ID 不跨身份空间成立。
     */
    public function withdrawAs(ExecutionIdentity $actor, int|string $instanceId): ApprovalInstance;

    /**
     * 本人发起实例列表（类型 + ID 双匹配，tenant 取 actor 执行上下文）。
     *
     * @param  array<string, mixed>  $filters
     */
    public function mine(ExecutionIdentity $actor, array $filters = [], ?int $perPage = null): LengthAwarePaginator;

    public function cancel(int $tenantId, int $operatorId, int|string $instanceId, string $reason): ApprovalInstance;

    /**
     * 扫描本租户到期的激活步骤并把实例置为 expired；返回处理数量。
     */
    public function expireDue(int $tenantId, bool $dryRun = false): int;

    public function find(int $tenantId, int|string $instanceId): ApprovalInstance;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function definitions(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator;

    public function definition(int $tenantId, int|string $definitionId): ApprovalDefinition;
}
