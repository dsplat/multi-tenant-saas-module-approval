<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Context\ExecutionIdentityManager;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Concerns\ApiResponse;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Approval\Adapters\ApprovalMatterAdapter;
use MultiTenantSaas\Modules\Approval\Contracts\ApprovalServiceContract;
use MultiTenantSaas\Modules\Approval\Http\Resources\UserApprovalResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Approval\Models\ApprovalStep;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\HistoryAccessPolicy;
use MultiTenantSaas\Modules\Matter\Models\Matter;
use MultiTenantSaas\Modules\Matter\Models\MatterParticipant;

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
 *
 * 三档可见性（C2O 缺口③，S0 §6.4）：参与未撤销 → 现有白名单全文；参与人被撤销
 * （matter_participants.revoked_at 非空）且在保留窗内 → 仅元数据（时间线无
 * step_no/outcome）；保留期外 / 未参与过 → 按不存在（404）。保留天数 owner=
 * 身份侧（config identity.history_access.retention_days，默认 30）。
 */
class UserApprovalController extends BaseController
{
    use ApiResponse;

    /** 三档：参与未撤销 → 现有白名单全文 */
    private const TIER_FULL = 'full';

    /** 三档：撤销且保留窗内 → 元数据视图 */
    private const TIER_METADATA = 'metadata';

    public function __construct(
        protected ApprovalServiceContract $approvals,
        protected UserApprovalResource $resource,
        protected HistoryAccessPolicy $historyAccess,
    ) {}

    /** 本人发起列表（排序 created_at + instance_id 双键，次级键纪律在服务层）；撤销且保留窗内按元数据裁剪 */
    public function mine(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $instances = $this->approvals->mine($actor, [], $perPage);

        $instances->setCollection($instances->getCollection()
            ->map(fn (ApprovalInstance $instance) => $this->listItemFor($request, $instance))
            ->filter()
            ->values());

        return $this->paginatedResponse($instances);
    }

    /** 列表项按三档裁剪：撤销且保留窗内只出元数据；窗外按不存在剔除（列表/详情同裁剪） */
    private function listItemFor(Request $request, ApprovalInstance $instance): ?array
    {
        $tier = $this->visibilityTier($request, $instance);

        if ($tier === null) {
            return null;
        }

        return $tier === self::TIER_METADATA
            ? $this->resource->metadataItem($instance, $this->matterIdFor($instance))
            : $this->resource->listItem($instance);
    }

    /** 详情（参与未撤销的发起人全文；撤销且保留窗内的参与人只出元数据；其余按不存在处理） */
    public function show(Request $request, string $instanceId): JsonResponse
    {
        $instance = $this->findInstance($instanceId);
        $tier = $instance === null ? null : $this->visibilityTier($request, $instance);

        if ($instance === null || $tier === null) {
            return $this->notFoundResponse('Approval instance not found');
        }

        return $this->successResponse($tier === self::TIER_METADATA
            ? $this->resource->metadataItem($instance, $this->matterIdFor($instance))
            : $this->resource->detail($instance));
    }

    /** 允许公开的时间线：公开动作与结果（决策意见全文 / 审批人身份永不出现在此） */
    public function timeline(Request $request, string $instanceId): JsonResponse
    {
        $instance = $this->findInstance($instanceId);
        $tier = $instance === null ? null : $this->visibilityTier($request, $instance);

        if ($instance === null || $tier === null) {
            return $this->notFoundResponse('Approval instance not found');
        }

        return $this->successResponse($this->buildTimeline($instance, $tier === self::TIER_METADATA));
    }

    /** 撤回（self-scoped）：发起人本人且参与未撤销；服务层 (类型, ID) 双匹配兜底 */
    public function withdraw(Request $request, string $instanceId): JsonResponse
    {
        $actor = $this->actor($request);
        $instance = $this->findInstance($instanceId);
        $tier = $instance === null ? null : $this->visibilityTier($request, $instance);

        if ($tier === null) {
            return $this->notFoundResponse('Approval instance not found');
        }

        // 写能力要求参与未撤销（撤销/调岗后保留窗只读元数据，不再产生业务动作）
        if ($tier !== self::TIER_FULL) {
            return $this->errorResponse('Forbidden', 403);
        }

        $instance = $this->approvals->withdrawAs($actor, $instanceId);

        return $this->successResponse($this->resource->detail($instance));
    }

    /** 实例定位（租户范围内）；不存在返回 null */
    private function findInstance(string $instanceId): ?ApprovalInstance
    {
        return ApprovalInstance::query()
            ->where('tenant_id', (int) TenantContext::getId())
            ->whereKey($instanceId)
            ->first();
    }

    /**
     * 三档可见性裁决（C2O 缺口③，S0 §6.4）：
     *   - full     ：本人（发起人）且参与未撤销（active member 语义不变）→ 现有白名单全文；
     *   - metadata ：参与人但 revoked_at 非空且在保留窗内 → 仅元数据（时间线无 content）；
     *   - null     ：其余（未参与过 / 保留期外）按不存在（404，不泄露存在性）。
     *
     * 实例全文视图维持现状「仅本人发起人」（身份空间 + ID 双匹配）；非本人有效参与人
     * 不因此获得实例视图。参与判定走 matter_participants（granted_at/revoked_at）+
     * 既有 own（initiated_by）逻辑，无参与记录的本人沿用现状全文视图。
     */
    private function visibilityTier(Request $request, ApprovalInstance $instance): ?string
    {
        $isSelf = $instance->wasInitiatedBy($this->actor($request));

        if (! $this->matterTablesReady()) {
            return $isSelf ? self::TIER_FULL : null;
        }

        $matterId = $this->matterIdFor($instance);

        if ($matterId === null) {
            return $isSelf ? self::TIER_FULL : null;
        }

        $states = MatterParticipant::query()
            ->where('actor_type', 'user')
            ->where('actor_id', $this->actor($request)->subjectId)
            ->where('matter_id', $matterId)
            ->get()
            ->map(fn (MatterParticipant $participant) => $participant->revoked_at)
            ->all();

        $hasActive = false;
        $latestRevokedAt = null;

        foreach ($states as $revokedAt) {
            if ($revokedAt === null) {
                $hasActive = true;

                continue;
            }
            if ($latestRevokedAt === null || $revokedAt > $latestRevokedAt) {
                $latestRevokedAt = $revokedAt;
            }
        }

        if ($isSelf && ($hasActive || $states === [])) {
            return self::TIER_FULL;
        }

        if ($states === [] || $hasActive) {
            return null;
        }

        return $this->historyAccess->isWithinWindow($latestRevokedAt)
            ? self::TIER_METADATA
            : null;
    }

    /**
     * 公开时间线：提交、步骤开始/结束（仅状态结果）、实例终态。
     *
     * 只投影可证的公开事实——不携带审批人身份、不还原决策意见（决策意见全文
     * 禁止出 C 端）；撤销且保留窗内的元数据视图只出 action/occurred_at（不给
     * step_no/outcome，S0 §6.4）；同秒多条以显式序号收尾，保证次序确定（次级键纪律）。
     *
     * @return list<array<string, mixed>>
     */
    private function buildTimeline(ApprovalInstance $instance, bool $metadataOnly = false): array
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

        $allowed = $metadataOnly
            ? HistoryAccessPolicy::METADATA_TIMELINE_FIELDS
            : UserApprovalResource::TIMELINE_FIELDS;

        return array_map(
            static fn (array $entry): array => array_intersect_key($entry, array_flip($allowed)),
            $entries,
        );
    }

    /** 事项 ID（S0 §6.1 matters 投影，domain_ref = approval_instance） */
    private function matterIdFor(ApprovalInstance $instance): ?string
    {
        if (! $this->matterTablesReady()) {
            return null;
        }

        $matter = Matter::query()
            ->where('tenant_id', (int) $instance->tenant_id)
            ->where('matter_type', ApprovalMatterAdapter::MATTER_TYPE)
            ->where('domain_ref_type', ApprovalMatterAdapter::DOMAIN_REF_TYPE)
            ->where('domain_ref_id', (string) $instance->instance_id)
            ->first();

        return $matter === null ? null : (string) $matter->matter_id;
    }

    private function matterTablesReady(): bool
    {
        return Schema::hasTable('matters') && Schema::hasTable('matter_participants');
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
