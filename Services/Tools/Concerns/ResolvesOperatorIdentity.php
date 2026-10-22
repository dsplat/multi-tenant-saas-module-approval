<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Tools\Concerns;

use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Context\ExecutionIdentityManager;

/**
 * 解析当前工具的 operator 身份
 *
 * 审批工具只面向 operator 身份；subjectId 即 operator_id。
 * 缺失身份时返回 null，由工具返回结构化错误而不是猜测。
 */
trait ResolvesOperatorIdentity
{
    protected function currentOperatorId(): ?int
    {
        $identity = app(ExecutionIdentityManager::class)->current();

        if ($identity === null
            || $identity->type !== ExecutionIdentity::TYPE_OPERATOR
            || $identity->subjectId === null) {
            return null;
        }

        $operatorId = (int) $identity->subjectId;

        return $operatorId > 0 ? $operatorId : null;
    }
}
