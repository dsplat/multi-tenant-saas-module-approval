<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Contracts;

use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 审批人解析器契约
 *
 * 步骤激活时才调用；返回值是 operator ID 列表。返回空列表表示解析失败，
 * 由 Approval 将步骤置 blocked（绝不自动通过）。
 */
interface ApproverResolverContract
{
    /**
     * 解析器键（定义 steps[].resolver 引用）。
     */
    public function key(): string;

    /**
     * @param  array<string, mixed>  $step  步骤配置（含 resolver/config/sla_hours 等）
     * @param  array<string, mixed>  $subjectSnapshot  发起时冻结的主体快照
     * @return array<int, int> operator ID 列表
     */
    public function resolve(int $tenantId, array $step, array $subjectSnapshot, ?ApprovalInstance $instance = null): array;
}
