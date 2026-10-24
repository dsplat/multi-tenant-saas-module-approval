<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Context\ExecutionIdentityManager;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Concerns\ApiResponse;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Approval\Contracts\ApprovalServiceContract;
use MultiTenantSaas\Modules\Approval\Http\Resources\UserApprovalResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Approval\Models\ApprovalStep;
use MultiTenantSaas\Modules\Auth\Models\User;

/**
 * C 端审批接口（User 面，C2O 缺口②）
 *
 * 路由面：/api/v1/user/approval/instances/...（独立前缀 + 独立 user.identify 组，
 * C2O 采用约定 v0.4 §4.2），与 console 面 /api/v1/approval/instances/... 不同
 * 命名空间，withdraw 不会同 method/URI 重复注册。
 *
 * 权限（S0 §6.3 + C2O v0.4 §4.1-4.2）：
 *   - 仅本人发起人可见（(initiated_by_type, initiated_by) 与当前 User 双匹配）；
 *   - 非本人 / 他租户一律按「不存在」处理（404，不泄露存在性）；
 *   - 撤回 = 发起人本人（同可见性门 + 服务层发起人双匹配兜底）；
 *   - 请求体自报身份（initiated_by / initiated_by_type / tenant_id）一律忽略；
 *   - definition_snapshot / subject_snapshot / 决策意见全文 / 审批人个人信息
 *     不出 C 端（见 UserApprovalResource 白名单）；
 *   - 无通用建单入口：User 发起由具体领域经 ApprovalService::initiateAs 验证
 *     主体操作权后创建（ApprovalUserSubjectContract），通用建单权限不开。
 */
class UserApprovalController extends BaseController
{
    use ApiResponse;

    public function __construct(
        protected ApprovalServiceContract $approvals,
        protected UserApprovalResource $resource,
    ) {}

    /** 本人发起列表（排序 created_at + instance_id 双键，次级键纪律在服务层） */
    public function mine(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $instances = $this->approvals->mine($actor, [], $perPage);

        $instances->setCollection($instances->getCollection()->map(
            fn (ApprovalInstance $instance) => $this->resource->listItem($instance),
        ));

        return $this->paginatedResponse($instances);
    }

    /** 详情（本人发起人可见；他人/他租户按不存在处理） */
    public function show(Request $request, string $instanceId): JsonResponse
    {
        $instance = $this->visibleInstance($request, $instanceId);

        if ($instance === null) {
            return $this->notFoundResponse('Approval instance not found');
        }

        return $this->successResponse($this->resource->detail($instance));
    }

    /** 允许公开的时间线：公开动作与结果（决策意见全文 / 审批人身份永不出现在此） */
    public function timeline(Request $request, string $instanceId): JsonResponse
    {
        $instance = $this->visibleInstance($request, $instanceId);

        if ($instance === null) {
            return $this->notFoundResponse('Approval instance not found');
        }

        return $this->successResponse($this->buildTimeline($instance));
    }

    /** 撤回（self-scoped）：发起人本人；服务层 (类型, ID) 双匹配兜底 */
    public function withdraw(Request $request, string $instanceId): JsonResponse
    {
        $actor = $this->actor($request);

        if ($this->visibleInstance($request, $instanceId) === null) {
            return $this->notFoundResponse('Approval instance not found');
        }

        $instance = $this->approvals->withdrawAs($actor, $instanceId);

        return $this->successResponse($this->resource->detail($instance));
    }

    /**
     * 可见性裁决：仅本人发起人（身份空间 + ID 双匹配）；其余按不存在（不泄露存在性）。
     */
    private function visibleInstance(Request $request, string $instanceId): ?ApprovalInstance
    {
        $actor = $this->actor($request);

        $instance = ApprovalInstance::query()
            ->where('tenant_id', (int) TenantContext::getId())
            ->whereKey($instanceId)
            ->first();

        if ($instance === null || ! $instance->wasInitiatedBy($actor)) {
            return null;
        }

        return $instance;
    }

    /**
     * 公开时间线：提交、步骤开始/结束（仅状态结果）、实例终态。
     *
     * 只投影可证的公开事实——不携带审批人身份、不还原决策意见（决策意见全文
     * 禁止出 C 端）；同秒多条以显式序号收尾，保证次序确定（次级键纪律）。
     *
     * @return list<array{action: string, occurred_at: ?string, step_no: ?int, outcome: ?string}>
     */
    private function buildTimeline(ApprovalInstance $instance): array
    {
        $entries = [];
        $seq = 0;

        $submittedAt = $instance->started_at ?? $instance->created_at;
        $entries[] = ['sort' => [$this->sortKey($submittedAt), $seq++]]
            + $this->resource->timelineEntry('submitted', $submittedAt);

        foreach ($instance->steps()->orderBy('step_no')->get() as $step) {
            if ($step->activated_at === null) {
                continue;
            }

            $entries[] = ['sort' => [$this->sortKey($step->activated_at), $seq++]]
                + $this->resource->timelineEntry('step_started', $step->activated_at, (int) $step->step_no);

            if ($step->closed_at !== null && $step->status !== ApprovalStep::STATUS_SKIPPED) {
                $entries[] = ['sort' => [$this->sortKey($step->closed_at), $seq++]]
                    + $this->resource->timelineEntry('step_finished', $step->closed_at, (int) $step->step_no, (string) $step->status);
            }
        }

        if ($instance->completed_at !== null) {
            $entries[] = ['sort' => [$this->sortKey($instance->completed_at), $seq++]]
                + $this->resource->timelineEntry('finished', $instance->completed_at, null, $instance->outcome);
        }

        usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(
            static fn (array $entry): array => array_intersect_key($entry, array_flip(UserApprovalResource::TIMELINE_FIELDS)),
            $entries,
        );
    }

    private function sortKey(mixed $value): int
    {
        return $value === null ? 0 : $value->getTimestamp();
    }

    /**
     * 可信 actor：只取执行上下文（认证身份 + 租户上下文）；
     * 请求体自报身份一律忽略，不参与 actor 构造。
     */
    private function actor(Request $request): ExecutionIdentity
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $tenantId = TenantContext::getId();
        abort_unless($tenantId !== null && ctype_digit((string) $tenantId) && (int) $tenantId > 0, 403);

        $identity = app(ExecutionIdentityManager::class)->current();

        if ($identity !== null
            && $identity->type === ExecutionIdentity::TYPE_USER
            && (string) $identity->subjectId === (string) $user->getAuthIdentifier()) {
            return $identity;
        }

        return new ExecutionIdentity(
            type: ExecutionIdentity::TYPE_USER,
            surface: ExecutionIdentity::SURFACE_WEB_APP,
            subjectId: (string) $user->getAuthIdentifier(),
            tenantId: (string) $tenantId,
            level: $user->email_verified_at !== null
                ? ExecutionIdentity::LEVEL_VERIFIED
                : ExecutionIdentity::LEVEL_AUTHENTICATED,
            authSource: 'sanctum',
        );
    }
}
