<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\ExecutionIdentityContract;
use MultiTenantSaas\Modules\Approval\Contracts\ApprovalServiceContract;
use MultiTenantSaas\Modules\Approval\Events\ApprovalEvent;
use MultiTenantSaas\Modules\Approval\Events\ApprovalExempted;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceApproved;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceCreated;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceExpired;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceRejected;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceSubmitted;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceWithdrawn;
use MultiTenantSaas\Modules\Approval\Events\ApprovalStepActivated;
use MultiTenantSaas\Modules\Approval\Events\ApprovalStepBlocked;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDefinition;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Approval\Models\ApprovalStep;
use MultiTenantSaas\Modules\Approval\Support\ApprovalSubjectRegistry;
use MultiTenantSaas\Modules\Approval\Support\ApproverResolverRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 审批实例生命周期（线性状态机）
 *
 * 事务边界：发起 / 决策 / 撤回 / 取消 / 到期全部在事务内完成状态迁移，
 * 领域事件在事务提交后统一派发（通知失败不回滚账本状态）。
 *
 * 并发控制：实例行锁 + 步骤行锁 + (step_id, operator_id) 唯一键；
 * 同主体同定义仅一个进行中实例由 active_unique_key 唯一约束兜底。
 */
class ApprovalService implements ApprovalServiceContract
{
    public function __construct(
        private readonly ExecutionIdentityContract $identity,
        private readonly ApprovalDefinitionService $definitions,
        private readonly ApprovalSubjectRegistry $subjects,
        private readonly ApproverResolverRegistry $resolvers,
        private readonly ApprovalQueryService $queries,
    ) {}

    public function initiate(int $tenantId, int $operatorId, array $data): ApprovalInstance
    {
        $this->assertTenant($tenantId);

        $idempotencyKey = $this->idempotencyKey($data);

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($tenantId, $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }
        }

        $definition = $this->definitions->resolveForInitiation(
            $tenantId,
            $data['definition_id'] ?? null,
            isset($data['definition_key']) ? (string) $data['definition_key'] : null,
            isset($data['definition_version']) ? (int) $data['definition_version'] : null,
        );

        $subjectType = $this->resolveSubjectType($data, $definition);
        $subjectId = $this->requiredSubjectId($data);
        $subjectSnapshot = $this->subjectSnapshot($tenantId, $subjectType, $subjectId, $data);
        $projectId = $this->resolveProjectId($subjectSnapshot, $data);
        $steps = $this->definitionSteps($definition);
        $definitionSnapshot = $this->definitionSnapshot($definition);
        $previousInstanceId = $this->resolvePreviousInstanceId($tenantId, $data, $subjectType, $subjectId, $definition->key);
        $activeUniqueKey = ApprovalInstance::activeUniqueKey($subjectType, $subjectId, $definition->key);

        $events = [];

        try {
            $instance = DB::transaction(function () use (
                $tenantId,
                $operatorId,
                $definition,
                $subjectType,
                $subjectId,
                $projectId,
                $subjectSnapshot,
                $definitionSnapshot,
                $steps,
                $idempotencyKey,
                $previousInstanceId,
                $activeUniqueKey,
                &$events,
            ): ApprovalInstance {
                $active = ApprovalInstance::query()
                    ->where('tenant_id', $tenantId)
                    ->where('active_unique_key', $activeUniqueKey)
                    ->lockForUpdate()
                    ->first();

                if ($active !== null) {
                    throw new ConflictHttpException('该主体已有进行中的审批实例');
                }

                $instance = ApprovalInstance::create([
                    'tenant_id' => $tenantId,
                    'definition_id' => $definition->definition_id,
                    'definition_key' => $definition->key,
                    'definition_version' => $definition->version,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'project_id' => $projectId,
                    'subject_snapshot' => $subjectSnapshot,
                    'definition_snapshot' => $definitionSnapshot,
                    'status' => ApprovalInstance::STATUS_PENDING,
                    'current_step_no' => 1,
                    'is_exempt' => false,
                    'initiated_by' => $operatorId,
                    'idempotency_key' => $idempotencyKey,
                    'previous_instance_id' => $previousInstanceId,
                    'active_unique_key' => $activeUniqueKey,
                    'outcome' => null,
                    'started_at' => now(),
                    'completed_at' => null,
                ]);

                foreach ($steps as $step) {
                    ApprovalStep::create([
                        'tenant_id' => $tenantId,
                        'instance_id' => $instance->instance_id,
                        'step_no' => (int) $step['step_no'],
                        'name' => (string) $step['name'],
                        'mode' => (string) $step['mode'],
                        'status' => ApprovalStep::STATUS_PENDING,
                        'approvers_snapshot' => [],
                    ]);
                }

                $events[] = ApprovalInstanceCreated::fromInstance($instance);
                $events[] = ApprovalInstanceSubmitted::fromInstance($instance, $instance->status);
                $this->activateStep($instance, 1, $events);

                return $instance->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($tenantId, $idempotencyKey);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw new ConflictHttpException('该主体已有进行中的审批实例', $exception);
        }

        $this->dispatchEvents($events);

        return $instance;
    }

    public function recordExemption(int $tenantId, int $operatorId, array $data): ApprovalInstance
    {
        $this->assertTenant($tenantId);

        $idempotencyKey = $this->idempotencyKey($data);

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($tenantId, $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }
        }

        $definition = $this->definitions->resolveForInitiation(
            $tenantId,
            $data['definition_id'] ?? null,
            isset($data['definition_key']) ? (string) $data['definition_key'] : null,
            isset($data['definition_version']) ? (int) $data['definition_version'] : null,
        );

        $settings = (array) $definition->settings;
        if (array_key_exists('allow_exemption', $settings) && $settings['allow_exemption'] === false) {
            throw new UnprocessableEntityHttpException('该审批定义不允许豁免');
        }

        $reason = trim((string) ($data['exemption_reason'] ?? $data['reason'] ?? ''));
        if ($reason === '') {
            throw new UnprocessableEntityHttpException('记录豁免必须填写原因');
        }

        $subjectType = $this->resolveSubjectType($data, $definition);
        $subjectId = $this->requiredSubjectId($data);
        $subjectSnapshot = $this->subjectSnapshot($tenantId, $subjectType, $subjectId, $data);
        $projectId = $this->resolveProjectId($subjectSnapshot, $data);
        $steps = $this->definitionSteps($definition);
        $definitionSnapshot = $this->definitionSnapshot($definition);
        $previousInstanceId = $this->resolvePreviousInstanceId($tenantId, $data, $subjectType, $subjectId, $definition->key);

        $events = [];

        try {
            $instance = DB::transaction(function () use (
                $tenantId,
                $operatorId,
                $definition,
                $subjectType,
                $subjectId,
                $projectId,
                $subjectSnapshot,
                $definitionSnapshot,
                $steps,
                $reason,
                $idempotencyKey,
                $previousInstanceId,
                &$events,
            ): ApprovalInstance {
                $instance = ApprovalInstance::create([
                    'tenant_id' => $tenantId,
                    'definition_id' => $definition->definition_id,
                    'definition_key' => $definition->key,
                    'definition_version' => $definition->version,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'project_id' => $projectId,
                    'subject_snapshot' => $subjectSnapshot,
                    'definition_snapshot' => $definitionSnapshot,
                    'status' => ApprovalInstance::STATUS_APPROVED,
                    'current_step_no' => null,
                    'is_exempt' => true,
                    'exemption_reason' => $reason,
                    'initiated_by' => $operatorId,
                    'idempotency_key' => $idempotencyKey,
                    'previous_instance_id' => $previousInstanceId,
                    'active_unique_key' => null,
                    'outcome' => ApprovalInstance::OUTCOME_EXEMPT,
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);

                foreach ($steps as $step) {
                    ApprovalStep::create([
                        'tenant_id' => $tenantId,
                        'instance_id' => $instance->instance_id,
                        'step_no' => (int) $step['step_no'],
                        'name' => (string) $step['name'],
                        'mode' => (string) $step['mode'],
                        'status' => ApprovalStep::STATUS_SKIPPED,
                        'approvers_snapshot' => [],
                        'closed_at' => now(),
                    ]);
                }

                $events[] = ApprovalInstanceCreated::fromInstance($instance);
                $events[] = ApprovalExempted::fromInstance($instance, ApprovalInstance::OUTCOME_EXEMPT);

                return $instance->refresh();
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($tenantId, $idempotencyKey);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw new ConflictHttpException('记录豁免失败，请重试', $exception);
        }

        $this->dispatchEvents($events);

        return $instance;
    }

    public function decide(int $tenantId, int $operatorId, int|string $instanceId, array $data): ApprovalDecision
    {
        $this->assertTenant($tenantId);

        $decisionValue = (string) ($data['decision'] ?? '');
        if (! in_array($decisionValue, ApprovalDecision::DECISIONS, true)) {
            throw new UnprocessableEntityHttpException('决策类型必须是 approve 或 reject');
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $maxLength = (int) config('approval.comment_max_length', 2000);

        if ($decisionValue === ApprovalDecision::DECISION_REJECT && $comment === '') {
            throw new UnprocessableEntityHttpException('驳回必须填写意见');
        }

        if (mb_strlen($comment) > $maxLength) {
            throw new UnprocessableEntityHttpException("审批意见长度不得超过 {$maxLength} 字");
        }

        $source = (string) ($data['source'] ?? ApprovalDecision::SOURCE_CONSOLE);
        if (! in_array($source, ApprovalDecision::SOURCES, true)) {
            $source = ApprovalDecision::SOURCE_CONSOLE;
        }

        $metadata = $data['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new UnprocessableEntityHttpException('决策 metadata 必须是对象');
        }

        $events = [];

        $decision = DB::transaction(function () use (
            $tenantId,
            $operatorId,
            $instanceId,
            $decisionValue,
            $comment,
            $source,
            $metadata,
            $data,
            &$events,
        ): ApprovalDecision {
            $instance = ApprovalInstance::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($instanceId)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                throw new NotFoundHttpException('审批实例不存在');
            }

            $step = null;
            if ($instance->current_step_no !== null) {
                $step = ApprovalStep::query()
                    ->where('tenant_id', $tenantId)
                    ->where('instance_id', $instance->instance_id)
                    ->where('step_no', $instance->current_step_no)
                    ->lockForUpdate()
                    ->first();
            }

            $sameStepDecision = $step === null ? null : ApprovalDecision::query()
                ->where('tenant_id', $tenantId)
                ->where('step_id', $step->step_id)
                ->where('operator_id', $operatorId)
                ->first();

            $anyInstanceDecision = ApprovalDecision::query()
                ->where('tenant_id', $tenantId)
                ->where('instance_id', $instance->instance_id)
                ->where('operator_id', $operatorId)
                ->orderByDesc('decided_at')
                ->orderByDesc('decision_id')
                ->first();

            if (! $instance->isActive()) {
                if ($anyInstanceDecision !== null) {
                    return $this->replayDecision($anyInstanceDecision, $decisionValue);
                }

                throw new ConflictHttpException('审批实例已进入终态，不可继续决策');
            }

            if ($step === null || $step->status !== ApprovalStep::STATUS_ACTIVE) {
                throw new ConflictHttpException('当前步骤不是可决策状态');
            }

            if (! $step->hasApprover($operatorId)) {
                throw new AccessDeniedHttpException('你不是当前步骤的审批人');
            }

            if ($sameStepDecision !== null) {
                return $this->replayDecision($sameStepDecision, $decisionValue);
            }

            try {
                $decision = ApprovalDecision::create([
                    'tenant_id' => $tenantId,
                    'instance_id' => $instance->instance_id,
                    'step_id' => $step->step_id,
                    'operator_id' => $operatorId,
                    'decision' => $decisionValue,
                    'comment' => $comment === '' ? null : $comment,
                    'source' => $source,
                    'ip' => $data['ip'] ?? null,
                    'user_agent' => $data['user_agent'] ?? null,
                    'request_id' => $data['request_id'] ?? null,
                    'decided_at' => now(),
                    'metadata' => $metadata,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $existing = ApprovalDecision::query()
                    ->where('tenant_id', $tenantId)
                    ->where('step_id', $step->step_id)
                    ->where('operator_id', $operatorId)
                    ->first();

                if ($existing === null) {
                    throw new ConflictHttpException('该审批人已提交过决策', $exception);
                }

                return $this->replayDecision($existing, $decisionValue);
            }

            $this->applyDecision($instance, $step, $events);

            return $decision;
        });

        $this->dispatchEvents($events);

        return $decision;
    }

    public function withdraw(int $tenantId, int $operatorId, int|string $instanceId): ApprovalInstance
    {
        $this->assertTenant($tenantId);

        $events = [];

        $instance = DB::transaction(function () use ($tenantId, $operatorId, $instanceId, &$events): ApprovalInstance {
            $instance = $this->lockInstance($tenantId, $instanceId);

            if (! $instance->isActive()) {
                throw new ConflictHttpException('审批实例已进入终态，不可撤回');
            }

            if ((int) $instance->initiated_by !== $operatorId) {
                throw new AccessDeniedHttpException('只有发起人本人可以撤回');
            }

            $settings = (array) (($instance->definition_snapshot ?? [])['settings'] ?? []);
            if (array_key_exists('allow_withdraw', $settings) && $settings['allow_withdraw'] === false) {
                throw new ConflictHttpException('该审批定义不允许撤回');
            }

            $step = $instance->current_step_no === null ? null : ApprovalStep::query()
                ->where('tenant_id', $tenantId)
                ->where('instance_id', $instance->instance_id)
                ->where('step_no', $instance->current_step_no)
                ->lockForUpdate()
                ->first();

            if ($step !== null && $step->status === ApprovalStep::STATUS_ACTIVE) {
                $hasApproved = ApprovalDecision::query()
                    ->where('tenant_id', $tenantId)
                    ->where('step_id', $step->step_id)
                    ->where('decision', ApprovalDecision::DECISION_APPROVE)
                    ->exists();

                if ($hasApproved) {
                    throw new ConflictHttpException('当前步骤已有通过决策，不可撤回');
                }
            }

            $this->skipOpenSteps($instance, $step?->step_no);

            $instance->status = ApprovalInstance::STATUS_WITHDRAWN;
            $instance->outcome = ApprovalInstance::OUTCOME_WITHDRAWN;
            $instance->current_step_no = null;
            $instance->active_unique_key = null;
            $instance->completed_at = now();
            $instance->save();

            $events[] = ApprovalInstanceWithdrawn::fromInstance($instance);

            return $instance->refresh();
        });

        $this->dispatchEvents($events);

        return $instance;
    }

    public function cancel(int $tenantId, int $operatorId, int|string $instanceId, string $reason): ApprovalInstance
    {
        $this->assertTenant($tenantId);

        $reason = trim($reason);
        if ($reason === '') {
            throw new UnprocessableEntityHttpException('管理员取消必须填写原因');
        }

        $instance = DB::transaction(function () use ($tenantId, $instanceId, $operatorId, $reason): ApprovalInstance {
            $instance = $this->lockInstance($tenantId, $instanceId);

            if (! $instance->isActive()) {
                throw new ConflictHttpException('审批实例已进入终态，不可取消');
            }

            $step = $instance->current_step_no === null ? null : ApprovalStep::query()
                ->where('tenant_id', $tenantId)
                ->where('instance_id', $instance->instance_id)
                ->where('step_no', $instance->current_step_no)
                ->lockForUpdate()
                ->first();

            $this->skipOpenSteps($instance, $step?->step_no);

            $instance->status = ApprovalInstance::STATUS_CANCELLED;
            $instance->outcome = ApprovalInstance::OUTCOME_CANCELLED;
            $instance->current_step_no = null;
            $instance->active_unique_key = null;
            $instance->completed_at = now();
            $instance->save();

            // 取消原因不进入账本敏感列，写结构化日志供审计检索
            Log::info('approval.instance.cancelled', [
                'tenant_id' => $tenantId,
                'instance_id' => (string) $instance->instance_id,
                'operator_id' => $operatorId,
                'reason' => $reason,
            ]);

            return $instance->refresh();
        });

        return $instance;
    }

    public function expireDue(int $tenantId, bool $dryRun = false): int
    {
        $this->assertTenant($tenantId);

        $now = now();
        $steps = ApprovalStep::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ApprovalStep::STATUS_ACTIVE)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->orderBy('due_at')
            ->orderBy('step_id')
            ->get();

        $expired = 0;
        $seen = [];

        foreach ($steps as $candidate) {
            if (isset($seen[(string) $candidate->instance_id])) {
                continue;
            }
            $seen[(string) $candidate->instance_id] = true;

            if ($dryRun) {
                $instance = ApprovalInstance::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($candidate->instance_id)
                    ->first();

                if ($instance !== null
                    && $instance->isActive()
                    && (int) $instance->current_step_no === (int) $candidate->step_no) {
                    $expired++;
                }

                continue;
            }

            $events = [];

            $affected = DB::transaction(function () use ($tenantId, $candidate, $now, &$events): bool {
                $instance = ApprovalInstance::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($candidate->instance_id)
                    ->lockForUpdate()
                    ->first();

                if ($instance === null
                    || ! $instance->isActive()
                    || (int) $instance->current_step_no !== (int) $candidate->step_no) {
                    return false;
                }

                $step = ApprovalStep::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($candidate->step_id)
                    ->lockForUpdate()
                    ->first();

                if ($step === null
                    || $step->status !== ApprovalStep::STATUS_ACTIVE
                    || $step->due_at === null
                    || $step->due_at->gt($now)) {
                    return false;
                }

                $step->status = ApprovalStep::STATUS_EXPIRED;
                $step->closed_at = $now;
                $step->save();

                $instance->status = ApprovalInstance::STATUS_EXPIRED;
                $instance->outcome = ApprovalInstance::OUTCOME_EXPIRED;
                $instance->active_unique_key = null;
                $instance->completed_at = $now;
                $instance->save();

                $events[] = ApprovalInstanceExpired::fromInstance($instance, null, (int) $step->step_no);

                return true;
            });

            if ($affected) {
                $expired++;
                $this->dispatchEvents($events);
            }
        }

        return $expired;
    }

    public function find(int $tenantId, int|string $instanceId): ApprovalInstance
    {
        $this->assertTenant($tenantId);

        $instance = ApprovalInstance::query()->whereKey($instanceId)->first();

        if ($instance === null || (int) $instance->tenant_id !== $tenantId) {
            throw new NotFoundHttpException('审批实例不存在');
        }

        return $instance;
    }

    public function paginate(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        return $this->queries->paginateInstances($tenantId, $filters, $perPage);
    }

    public function definitions(int $tenantId, array $filters = [], ?int $perPage = null): LengthAwarePaginator
    {
        return $this->definitions->paginate($tenantId, $filters, $perPage);
    }

    public function definition(int $tenantId, int|string $definitionId): ApprovalDefinition
    {
        return $this->definitions->find($tenantId, $definitionId);
    }

    // ------------------------------------------------------------------
    // 状态机内部实现
    // ------------------------------------------------------------------

    /**
     * 激活指定步骤：解析审批人并快照；解析为空 → blocked（绝不自动通过）。
     *
     * @param  list<ApprovalEvent>  $events
     */
    private function activateStep(ApprovalInstance $instance, int $stepNo, array &$events): void
    {
        $step = ApprovalStep::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('instance_id', $instance->instance_id)
            ->where('step_no', $stepNo)
            ->lockForUpdate()
            ->first();

        if ($step === null) {
            throw new ConflictHttpException('审批步骤不存在，无法激活');
        }

        $config = $instance->definitionStepConfig($stepNo);
        $approvers = $config === [] ? [] : $this->resolvers->resolve(
            (string) ($config['resolver'] ?? ''),
            (int) $instance->tenant_id,
            $config,
            (array) ($instance->subject_snapshot ?? []),
            $instance,
        );

        $instance->current_step_no = $stepNo;
        $instance->status = $stepNo === 1
            ? ApprovalInstance::STATUS_PENDING
            : ApprovalInstance::STATUS_IN_PROGRESS;

        if ($approvers === []) {
            $step->status = ApprovalStep::STATUS_BLOCKED;
            $step->approvers_snapshot = [];
            $step->activated_at = now();
            $step->due_at = null;
            $step->blocked_reason = $config === [] ? '缺少步骤配置，无法解析审批人' : '未解析到审批人';
            $step->save();

            $instance->save();
            $events[] = ApprovalStepBlocked::fromInstance($instance, ApprovalStep::STATUS_BLOCKED, $stepNo);

            return;
        }

        $slaHours = isset($config['sla_hours']) ? (int) $config['sla_hours'] : 0;
        $activatedAt = now();

        $step->status = ApprovalStep::STATUS_ACTIVE;
        $step->approvers_snapshot = $approvers;
        $step->activated_at = $activatedAt;
        $step->due_at = $slaHours > 0 ? $activatedAt->copy()->addHours($slaHours) : null;
        $step->closed_at = null;
        $step->blocked_reason = null;
        $step->save();

        $instance->save();
        $events[] = ApprovalStepActivated::fromInstance($instance, ApprovalStep::STATUS_ACTIVE, $stepNo);
    }

    /**
     * 根据已落库的决策推进步骤与实例。
     *
     * @param  list<ApprovalEvent>  $events
     */
    private function applyDecision(ApprovalInstance $instance, ApprovalStep $step, array &$events): void
    {
        $decisions = ApprovalDecision::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('step_id', $step->step_id)
            ->get();

        $rejected = $decisions->contains(static fn (ApprovalDecision $decision): bool => $decision->decision === ApprovalDecision::DECISION_REJECT);

        if ($rejected) {
            $step->status = ApprovalStep::STATUS_REJECTED;
            $step->closed_at = now();
            $step->save();

            $instance->status = ApprovalInstance::STATUS_REJECTED;
            $instance->outcome = ApprovalInstance::OUTCOME_REJECTED;
            $instance->active_unique_key = null;
            $instance->completed_at = now();
            $instance->save();

            $events[] = ApprovalInstanceRejected::fromInstance($instance, ApprovalInstance::OUTCOME_REJECTED, (int) $step->step_no);

            return;
        }

        $approvedCount = $decisions
            ->where('decision', ApprovalDecision::DECISION_APPROVE)
            ->count();

        $required = $step->mode === ApprovalStep::MODE_ALL
            ? count($step->approverIds())
            : 1;

        if ($approvedCount < $required) {
            return;
        }

        $step->status = ApprovalStep::STATUS_APPROVED;
        $step->closed_at = now();
        $step->save();

        $nextStep = ApprovalStep::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('instance_id', $instance->instance_id)
            ->where('step_no', $step->step_no + 1)
            ->first();

        if ($nextStep === null) {
            $instance->status = ApprovalInstance::STATUS_APPROVED;
            $instance->outcome = ApprovalInstance::OUTCOME_APPROVED;
            $instance->active_unique_key = null;
            $instance->completed_at = now();
            $instance->save();

            $events[] = ApprovalInstanceApproved::fromInstance($instance, ApprovalInstance::OUTCOME_APPROVED, (int) $step->step_no);

            return;
        }

        $this->activateStep($instance, (int) $nextStep->step_no, $events);
    }

    private function replayDecision(ApprovalDecision $existing, string $requested): ApprovalDecision
    {
        if ($existing->decision !== $requested) {
            throw new ConflictHttpException('已提交相反决策，不能重复提交');
        }

        return $existing;
    }

    private function lockInstance(int $tenantId, int|string $instanceId): ApprovalInstance
    {
        $instance = ApprovalInstance::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($instanceId)
            ->lockForUpdate()
            ->first();

        if ($instance === null) {
            throw new NotFoundHttpException('审批实例不存在');
        }

        return $instance;
    }

    /**
     * 撤回/取消时把未关闭的步骤统一置为 skipped（含当前 blocked 步骤与后续 pending）。
     */
    private function skipOpenSteps(ApprovalInstance $instance, ?int $currentStepNo): void
    {
        $query = ApprovalStep::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('instance_id', $instance->instance_id)
            ->whereIn('status', [
                ApprovalStep::STATUS_PENDING,
                ApprovalStep::STATUS_ACTIVE,
                ApprovalStep::STATUS_BLOCKED,
            ]);

        if ($currentStepNo !== null) {
            $query->orderByRaw('case when step_no = ? then 0 else 1 end', [$currentStepNo]);
        }

        foreach ($query->get() as $step) {
            $step->status = ApprovalStep::STATUS_SKIPPED;
            $step->closed_at = now();
            $step->save();
        }
    }

    // ------------------------------------------------------------------
    // 入参与快照
    // ------------------------------------------------------------------

    private function resolveSubjectType(array $data, ApprovalDefinition $definition): string
    {
        $subjectType = trim((string) ($data['subject_type'] ?? $definition->subject_type));

        if ($subjectType === '' || strlen($subjectType) > 100) {
            throw new UnprocessableEntityHttpException('缺少合法的 subject_type');
        }

        if ($subjectType !== $definition->subject_type) {
            throw new UnprocessableEntityHttpException('subject_type 与审批定义不一致');
        }

        return $subjectType;
    }

    private function requiredSubjectId(array $data): string
    {
        $subjectId = trim((string) ($data['subject_id'] ?? ''));

        if ($subjectId === '' || strlen($subjectId) > 100) {
            throw new UnprocessableEntityHttpException('缺少合法的 subject_id');
        }

        return $subjectId;
    }

    /**
     * 主体快照只能由注册的主体契约生成：客户端传入的快照一律忽略，
     * 防止伪造金额/负责人等敏感属性进入审批账本。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function subjectSnapshot(int $tenantId, string $subjectType, string $subjectId, array $data): array
    {
        if (! $this->subjects->has($subjectType)) {
            throw new UnprocessableEntityHttpException("未注册审批主体契约：{$subjectType}");
        }

        $subject = $this->subjects->resolve($subjectType, $tenantId, $subjectId);

        if ($subject === null) {
            throw new UnprocessableEntityHttpException('审批主体不存在或不允许发起审批');
        }

        if ($subject->tenantId !== $tenantId
            || $subject->subjectType !== $subjectType
            || $subject->subjectId !== $subjectId) {
            throw new UnprocessableEntityHttpException('审批主体与租户/类型/ID 不一致');
        }

        return $subject->toSnapshot();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $data
     */
    private function resolveProjectId(array $snapshot, array $data): int|string|null
    {
        $projectId = $snapshot['project_id'] ?? $data['project_id'] ?? null;

        return $projectId === '' ? null : $projectId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitionSteps(ApprovalDefinition $definition): array
    {
        $steps = (array) $definition->steps;
        if ($steps === []) {
            throw new UnprocessableEntityHttpException('审批定义没有可用步骤');
        }

        return array_values($steps);
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionSnapshot(ApprovalDefinition $definition): array
    {
        return [
            'definition_id' => $definition->definition_id,
            'key' => $definition->key,
            'name' => $definition->name,
            'subject_type' => $definition->subject_type,
            'version' => $definition->version,
            'steps' => $definition->steps,
            'settings' => $definition->settings,
        ];
    }

    private function resolvePreviousInstanceId(
        int $tenantId,
        array $data,
        string $subjectType,
        string $subjectId,
        string $definitionKey,
    ): int|string|null {
        $previous = $data['previous_instance_id'] ?? null;

        if ($previous !== null && $previous !== '') {
            $instance = ApprovalInstance::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($previous)
                ->first();

            if ($instance === null
                || $instance->subject_type !== $subjectType
                || $instance->subject_id !== $subjectId
                || $instance->definition_key !== $definitionKey) {
                throw new UnprocessableEntityHttpException('previous_instance_id 与本次审批主体不一致');
            }

            return $instance->instance_id;
        }

        $latest = ApprovalInstance::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('definition_key', $definitionKey)
            ->orderByDesc('created_at')
            ->orderByDesc('instance_id')
            ->first();

        return $latest?->instance_id;
    }

    private function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key === null) {
            return null;
        }

        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        if (strlen($key) > 191) {
            throw new UnprocessableEntityHttpException('idempotency_key 长度不得超过 191');
        }

        return $key;
    }

    private function findByIdempotencyKey(int $tenantId, string $key): ?ApprovalInstance
    {
        return ApprovalInstance::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function assertTenant(int $tenantId): void
    {
        $this->identity->assertTenant((string) $tenantId);
    }

    /**
     * @param  list<ApprovalEvent>  $events
     */
    private function dispatchEvents(array $events): void
    {
        foreach ($events as $event) {
            event($event);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === '1062'
            || $driverCode === '19'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
