<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Access\DataScopeContext;
use MultiTenantSaas\Contracts\ExecutionIdentityContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Approval\Models\ApprovalStep;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 审批查询、统计、时间线与导出
 *
 * 只读服务：不做状态迁移。存在 DataScopeContext::ambient() 时先 applyTo()
 * 注入行级谓词与字段白名单（工具链数据范围），再叠加业务过滤。
 *
 * 导出与 AI 摘要的脱敏边界：
 *   - 导出不返回主体属性快照、定义快照、决策意见、幂等键；
 *   - AI 摘要只返回本人决策的意见，其他审批人意见标记为受限。
 */
class ApprovalQueryService
{
    public function __construct(
        private readonly ExecutionIdentityContract $identity,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateInstances(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $this->assertTenant($tenantId);

        return $this->baseQuery($tenantId, $filters)
            ->orderByDesc('approval_instances.created_at')
            ->orderByDesc('approval_instances.instance_id')
            ->paginate($this->perPage($perPage));
    }

    /**
     * 我的待办：当前 operator 是 active 步骤快照审批人的进行中实例，按截止时间升序。
     *
     * @param  array<string, mixed>  $filters
     */
    public function pendingFor(int $tenantId, int $operatorId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        $this->assertTenant($tenantId);

        $query = $this->baseQuery($tenantId, $filters)
            ->whereIn('approval_instances.status', ApprovalInstance::ACTIVE_STATUSES)
            ->whereHas('steps', function (Builder $stepQuery) use ($operatorId): void {
                $stepQuery->where('approval_steps.status', ApprovalStep::STATUS_ACTIVE)
                    ->where('approval_steps.step_no', DB::raw('approval_instances.current_step_no'))
                    ->whereJsonContains('approval_steps.approvers_snapshot', $operatorId);
            });

        $dueAt = ApprovalStep::query()
            ->select('due_at')
            ->whereColumn('approval_steps.instance_id', 'approval_instances.instance_id')
            ->where('approval_steps.status', ApprovalStep::STATUS_ACTIVE)
            ->limit(1);

        return $query
            ->orderBy($dueAt)
            ->orderByDesc('approval_instances.created_at')
            ->orderByDesc('approval_instances.instance_id')
            ->paginate($this->perPage($perPage));
    }

    /**
     * 时间线：创建、步骤激活/阻塞、每次决策、实例终态，按时间排序。
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(int $tenantId, int|string $instanceId): array
    {
        $instance = $this->findVisible($tenantId, $instanceId);

        $instance->load(['steps', 'decisions']);

        $stepNumbers = [];
        foreach ($instance->steps as $step) {
            $stepNumbers[(string) $step->step_id] = (int) $step->step_no;
        }

        $events = [[
            'type' => 'instance_created',
            'at' => $this->formatTime($instance->created_at),
            'operator_id' => $instance->initiated_by,
            'status' => $instance->status,
            'seq' => 0,
        ]];

        $seq = 1;

        foreach ($instance->steps as $step) {
            if ($step->activated_at !== null) {
                $events[] = [
                    'type' => 'step_activated',
                    'at' => $this->formatTime($step->activated_at),
                    'step_no' => (int) $step->step_no,
                    'name' => $step->name,
                    'mode' => $step->mode,
                    'due_at' => $this->formatTime($step->due_at),
                    'seq' => $seq++,
                ];
            }

            if ($step->status === ApprovalStep::STATUS_BLOCKED) {
                $events[] = [
                    'type' => 'step_blocked',
                    'at' => $this->formatTime($step->activated_at ?? $step->updated_at),
                    'step_no' => (int) $step->step_no,
                    'name' => $step->name,
                    'reason' => $step->blocked_reason,
                    'seq' => $seq++,
                ];
            }
        }

        foreach ($instance->decisions as $decision) {
            $events[] = [
                'type' => 'decision',
                'at' => $this->formatTime($decision->decided_at),
                'step_no' => $stepNumbers[(string) $decision->step_id] ?? null,
                'operator_id' => $decision->operator_id,
                'decision' => $decision->decision,
                'comment' => $decision->comment,
                'source' => $decision->source,
                'seq' => $seq++,
            ];
        }

        if ($instance->completed_at !== null) {
            $events[] = [
                'type' => 'instance_' . $instance->status,
                'at' => $this->formatTime($instance->completed_at),
                'outcome' => $instance->outcome,
                'is_exempt' => (bool) $instance->is_exempt,
                'exemption_reason' => $instance->exemption_reason,
                'seq' => $seq++,
            ];
        }

        usort($events, static function (array $left, array $right): int {
            return [$left['at'] ?? '', $left['seq']] <=> [$right['at'] ?? '', $right['seq']];
        });

        return array_map(static function (array $event): array {
            unset($event['seq']);

            return $event;
        }, $events);
    }

    /**
     * 统计：总量、状态分布、通过率、平均时长、超时与阻塞数，可按定义/主体/状态聚合。
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function stats(int $tenantId, array $filters = []): array
    {
        $this->assertTenant($tenantId);

        $base = $this->baseQuery($tenantId, $filters);

        $statusCounts = [];
        $rows = (clone $base)
            ->reorder()
            ->select('approval_instances.status', DB::raw('count(*) as aggregate'))
            ->groupBy('approval_instances.status')
            ->get();

        foreach ($rows as $row) {
            $statusCounts[(string) $row->status] = (int) $row->aggregate;
        }

        $approved = $statusCounts[ApprovalInstance::STATUS_APPROVED] ?? 0;
        $rejected = $statusCounts[ApprovalInstance::STATUS_REJECTED] ?? 0;
        $decided = $approved + $rejected;

        $durations = (clone $base)
            ->whereNotNull('approval_instances.completed_at')
            ->orderBy('approval_instances.instance_id')
            ->get(['approval_instances.started_at', 'approval_instances.completed_at']);

        $durationHours = [];
        foreach ($durations as $row) {
            if ($row->started_at === null || $row->completed_at === null) {
                continue;
            }
            $durationHours[] = abs($row->completed_at->diffInMinutes($row->started_at)) / 60;
        }

        $stats = [
            'total' => (int) (clone $base)->count(),
            'status_counts' => $statusCounts,
            'pending' => ($statusCounts[ApprovalInstance::STATUS_PENDING] ?? 0)
                + ($statusCounts[ApprovalInstance::STATUS_IN_PROGRESS] ?? 0),
            'approved' => $approved,
            'rejected' => $rejected,
            'withdrawn' => $statusCounts[ApprovalInstance::STATUS_WITHDRAWN] ?? 0,
            'cancelled' => $statusCounts[ApprovalInstance::STATUS_CANCELLED] ?? 0,
            'expired' => $statusCounts[ApprovalInstance::STATUS_EXPIRED] ?? 0,
            'exempt' => (int) (clone $base)->where('approval_instances.is_exempt', true)->count(),
            'approval_rate' => $decided === 0 ? 0.0 : round($approved / $decided * 100, 2),
            'average_duration_hours' => $durationHours === [] ? 0.0 : round(array_sum($durationHours) / count($durationHours), 2),
            'overdue' => (int) (clone $base)
                ->whereIn('approval_instances.status', ApprovalInstance::ACTIVE_STATUSES)
                ->whereHas('steps', function (Builder $stepQuery): void {
                    $stepQuery->where('approval_steps.status', ApprovalStep::STATUS_ACTIVE)
                        ->where('approval_steps.step_no', DB::raw('approval_instances.current_step_no'))
                        ->whereNotNull('approval_steps.due_at')
                        ->where('approval_steps.due_at', '<', now());
                })
                ->count(),
            'blocked' => (int) (clone $base)
                ->whereIn('approval_instances.status', ApprovalInstance::ACTIVE_STATUSES)
                ->whereHas('steps', function (Builder $stepQuery): void {
                    $stepQuery->where('approval_steps.status', ApprovalStep::STATUS_BLOCKED)
                        ->where('approval_steps.step_no', DB::raw('approval_instances.current_step_no'));
                })
                ->count(),
        ];

        $groupBy = (string) ($filters['group_by'] ?? '');
        if (in_array($groupBy, ['definition_key', 'subject_type', 'status', 'outcome'], true)) {
            $groups = (clone $base)
                ->reorder()
                ->select('approval_instances.' . $groupBy, DB::raw('count(*) as aggregate'))
                ->groupBy('approval_instances.' . $groupBy)
                ->orderByDesc('aggregate')
                ->get();

            $stats['groups'] = $groups->map(static fn ($row): array => [
                'key' => $row->{$groupBy},
                'total' => (int) $row->aggregate,
            ])->all();
        }

        return $stats;
    }

    /**
     * 导出（P0 同步生成，脱敏）：不含主体属性快照、定义快照、决策意见与幂等键。
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, truncated: bool}
     */
    public function exportRows(int $tenantId, array $filters = []): array
    {
        $this->assertTenant($tenantId);

        $limit = max(1, min(5000, (int) config('approval.export_max_rows', 1000)));

        $base = $this->baseQuery($tenantId, $filters);
        $total = (int) (clone $base)->count();

        $rows = (clone $base)
            ->orderByDesc('approval_instances.created_at')
            ->orderByDesc('approval_instances.instance_id')
            ->limit($limit)
            ->get()
            ->map(fn (ApprovalInstance $instance): array => [
                'instance_id' => $instance->instance_id,
                'definition_key' => $instance->definition_key,
                'definition_version' => $instance->definition_version,
                'subject_type' => $instance->subject_type,
                'subject_id' => $instance->subject_id,
                'project_id' => $instance->project_id,
                'status' => $instance->status,
                'outcome' => $instance->outcome,
                'is_exempt' => (bool) $instance->is_exempt,
                'current_step_no' => $instance->current_step_no,
                'initiated_by' => $instance->initiated_by,
                'started_at' => $this->formatTime($instance->started_at),
                'completed_at' => $this->formatTime($instance->completed_at),
            ])
            ->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
        ];
    }

    /**
     * AI 摘要：只返回定位、进度与意见裁剪结果，不含主体敏感属性。
     *
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, int|string $instanceId, int $operatorId): array
    {
        $instance = $this->findVisible($tenantId, $instanceId);
        $instance->load(['steps', 'decisions']);

        $snapshot = (array) ($instance->subject_snapshot ?? []);

        $decisions = [];
        foreach ($instance->decisions as $decision) {
            $own = (int) $decision->operator_id === $operatorId;
            $decisions[] = [
                'operator_id' => $decision->operator_id,
                'decision' => $decision->decision,
                'comment' => $own ? $decision->comment : null,
                'comment_restricted' => ! $own && $decision->comment !== null,
                'decided_at' => $this->formatTime($decision->decided_at),
            ];
        }

        $steps = [];
        foreach ($instance->steps as $step) {
            $steps[] = [
                'step_no' => (int) $step->step_no,
                'name' => $step->name,
                'mode' => $step->mode,
                'status' => $step->status,
                'approver_count' => count($step->approverIds()),
                'activated_at' => $this->formatTime($step->activated_at),
                'due_at' => $this->formatTime($step->due_at),
                'closed_at' => $this->formatTime($step->closed_at),
                'blocked_reason' => $step->blocked_reason,
            ];
        }

        return [
            'instance_id' => $instance->instance_id,
            'definition_key' => $instance->definition_key,
            'definition_version' => $instance->definition_version,
            'subject' => [
                'type' => $instance->subject_type,
                'id' => $instance->subject_id,
                'title' => $snapshot['title'] ?? null,
                'summary' => $snapshot['summary'] ?? null,
                'project_id' => $instance->project_id,
                'detail_url' => $snapshot['detail_url'] ?? null,
            ],
            'status' => $instance->status,
            'outcome' => $instance->outcome,
            'current_step_no' => $instance->current_step_no,
            'is_exempt' => (bool) $instance->is_exempt,
            'exemption_reason' => $instance->is_exempt ? $instance->exemption_reason : null,
            'initiated_by' => $instance->initiated_by,
            'started_at' => $this->formatTime($instance->started_at),
            'completed_at' => $this->formatTime($instance->completed_at),
            'steps' => $steps,
            'decisions' => $decisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(int $tenantId, array $filters): Builder
    {
        $query = ApprovalInstance::query()
            ->where('approval_instances.tenant_id', $tenantId);

        $status = $filters['status'] ?? null;
        if (is_array($status) && $status !== []) {
            $query->whereIn('approval_instances.status', array_values(array_intersect($status, ApprovalInstance::STATUSES)));
        } elseif (is_string($status) && $status !== '') {
            $query->where('approval_instances.status', $status);
        }

        foreach ([
            'subject_type' => 'approval_instances.subject_type',
            'subject_id' => 'approval_instances.subject_id',
            'definition_key' => 'approval_instances.definition_key',
            'definition_id' => 'approval_instances.definition_id',
            'definition_version' => 'approval_instances.definition_version',
            'project_id' => 'approval_instances.project_id',
            'initiated_by' => 'approval_instances.initiated_by',
            'outcome' => 'approval_instances.outcome',
        ] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }

        if (array_key_exists('is_exempt', $filters)) {
            $query->where('approval_instances.is_exempt', (bool) $filters['is_exempt']);
        }

        if (isset($filters['participant_id']) && $filters['participant_id'] !== '') {
            $participantId = (int) $filters['participant_id'];
            $query->whereHas('decisions', function (Builder $decisionQuery) use ($participantId): void {
                $decisionQuery->where('approval_decisions.operator_id', $participantId);
            });
        }

        if (isset($filters['keyword']) && $filters['keyword'] !== '') {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->where(function (Builder $inner) use ($keyword): void {
                $inner->where('approval_instances.definition_key', 'like', $keyword)
                    ->orWhere('approval_instances.subject_id', 'like', $keyword);
            });
        }

        if (isset($filters['from']) && $filters['from'] !== '') {
            $query->where('approval_instances.started_at', '>=', $filters['from']);
        }

        if (isset($filters['to']) && $filters['to'] !== '') {
            $query->where('approval_instances.started_at', '<=', $filters['to']);
        }

        $scope = DataScopeContext::ambient();
        if ($scope !== null) {
            $scope->applyTo($query);
        }

        return $query;
    }

    private function findVisible(int $tenantId, int|string $instanceId): ApprovalInstance
    {
        $query = ApprovalInstance::query()
            ->where('approval_instances.tenant_id', $tenantId)
            ->whereKey($instanceId);

        $scope = DataScopeContext::ambient();
        if ($scope !== null) {
            $scope->applyTo($query);
        }

        $instance = $query->first();

        if ($instance === null || (int) $instance->tenant_id !== $tenantId) {
            throw new NotFoundHttpException('审批实例不存在');
        }

        return $instance;
    }

    private function perPage(?int $perPage): int
    {
        $default = (int) config('approval.per_page', 20);

        return max(1, min(100, $perPage ?? $default));
    }

    private function formatTime(mixed $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }

    private function assertTenant(int $tenantId): void
    {
        $this->identity->assertTenant((string) $tenantId);
    }
}
