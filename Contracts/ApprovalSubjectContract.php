<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Contracts;

use MultiTenantSaas\Modules\Approval\Support\ApprovalSubjectResult;

/**
 * 审批主体契约（宿主实现）
 *
 * Approval 只读主体：不写主体表、不解析业务阈值。宿主注册本契约后，
 * 发起审批时由 Approval 调用 resolve() 取回主体的只读快照。
 */
interface ApprovalSubjectContract
{
    /**
     * 稳定主体类型（如 project、contract），租户内唯一命名空间。
     */
    public function subjectType(): string;

    /**
     * 解析主体；不存在 / 已删除 / 不允许发起时返回 null。
     *
     * 返回值必须携带 tenantId，Approval 会用它做租户一致性校验。
     */
    public function resolve(int $tenantId, string $subjectId): ?ApprovalSubjectResult;
}
