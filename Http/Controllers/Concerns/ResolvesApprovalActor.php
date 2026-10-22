<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use MultiTenantSaas\Context\ExecutionIdentityManager;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 审批控制器的操作者与租户解析
 *
 * operator 身份由 operator.identify 装载；这里只读取，不从请求参数推导。
 */
trait ResolvesApprovalActor
{
    protected function tenantId(): int
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null || ! ctype_digit((string) $tenantId) || (int) $tenantId <= 0) {
            throw new AccessDeniedHttpException('缺少租户上下文');
        }

        return (int) $tenantId;
    }

    protected function operatorId(Request $request): int
    {
        $actor = $request->user();
        $operatorId = $actor?->getKey();

        if ($operatorId === null) {
            $identity = app(ExecutionIdentityManager::class)->current();
            $operatorId = $identity?->subjectId;
        }

        if ($operatorId === null || (int) $operatorId <= 0) {
            throw new AccessDeniedHttpException('缺少 operator 身份');
        }

        return (int) $operatorId;
    }
}
