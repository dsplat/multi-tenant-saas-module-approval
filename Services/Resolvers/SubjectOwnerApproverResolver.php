<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Resolvers;

use MultiTenantSaas\Modules\Approval\Contracts\ApproverResolverContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 主体负责人审批人解析器
 *
 * 主体快照冻结时记录的 owner_operator_id。
 */
final class SubjectOwnerApproverResolver implements ApproverResolverContract
{
    public function key(): string
    {
        return 'subject_owner';
    }

    public function resolve(int $tenantId, array $step, array $subjectSnapshot, ?ApprovalInstance $instance = null): array
    {
        $owner = $subjectSnapshot['owner_operator_id'] ?? null;

        return $owner === null ? [] : [(int) $owner];
    }
}
