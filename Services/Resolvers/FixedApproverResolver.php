<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Resolvers;

use MultiTenantSaas\Modules\Approval\Contracts\ApproverResolverContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 指定人员审批人解析器
 *
 * 步骤配置：{"resolver":"fixed","config":{"operator_ids":[1,2]}}
 */
final class FixedApproverResolver implements ApproverResolverContract
{
    public function key(): string
    {
        return 'fixed';
    }

    public function resolve(int $tenantId, array $step, array $subjectSnapshot, ?ApprovalInstance $instance = null): array
    {
        $config = (array) ($step['config'] ?? []);
        $ids = $config['operator_ids'] ?? $step['operator_ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_map(static fn ($id): int => (int) $id, $ids);
    }
}
