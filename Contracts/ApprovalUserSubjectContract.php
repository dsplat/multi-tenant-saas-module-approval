<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Contracts;

/**
 * User 可发起的审批主体契约（领域证明，C2O 采用约定 v0.4 §4.1）
 *
 * User 不获得任意 subject_type 的通用建单权限（通用建单权限不开）：
 * `ApprovalService::initiateAs` 在 actor 为 User 时，要求主体契约显式实现本接口并
 * 由**具体领域**裁决该 User 是否可对该主体发起审批（本人归属 / 操作权证明）。
 * 未实现本接口的主体类型一律拒绝 User 发起——业务模块内部代 User 调用同样必须
 * 经过这道主体操作权证明，请求体自报身份不构成证明。
 *
 * Operator 发起不走本接口（保留兼容入口），仍由 ApprovalSubjectContract::resolve
 * 的「不存在 / 不允许发起返回 null」语义裁决。
 */
interface ApprovalUserSubjectContract extends ApprovalSubjectContract
{
    /**
     * 该 User 是否可对此主体发起审批（领域自行核对归属 / 操作权）。
     */
    public function userCanOperate(int $tenantId, string $subjectId, int $userId): bool;
}
