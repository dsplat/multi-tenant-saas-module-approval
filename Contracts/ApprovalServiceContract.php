<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDefinition;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 审批实例服务契约
 *
 * 只覆盖实例生命周期（发起 / 决策 / 撤回 / 取消 / 豁免 / 到期）。
 * 定义维护见 ApprovalDefinitionService，查询见 ApprovalQueryService。
 */
interface ApprovalServiceContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function initiate(int $tenantId, int $operatorId, array $data): ApprovalInstance;

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
