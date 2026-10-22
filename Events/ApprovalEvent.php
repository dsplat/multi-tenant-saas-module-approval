<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * 审批领域事件基类
 *
 * 载荷只含定位与结果信息：租户、实例、定义 key/version、主体类型/ID、项目、结果、时间。
 * 不包含审批意见正文（宿主需要时按权限查询）。
 */
abstract class ApprovalEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $instanceId,
        public readonly string $definitionKey,
        public readonly int $definitionVersion,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly int|string|null $projectId,
        public readonly ?string $result,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?int $stepNo = null,
    ) {}

    public static function fromInstance(ApprovalInstance $instance, ?string $result = null, ?int $stepNo = null): static
    {
        return new static(
            tenantId: (int) $instance->tenant_id,
            instanceId: (string) $instance->instance_id,
            definitionKey: (string) $instance->definition_key,
            definitionVersion: (int) $instance->definition_version,
            subjectType: (string) $instance->subject_type,
            subjectId: (string) $instance->subject_id,
            projectId: $instance->project_id,
            result: $result,
            occurredAt: CarbonImmutable::now(),
            stepNo: $stepNo,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'instance_id' => $this->instanceId,
            'definition_key' => $this->definitionKey,
            'definition_version' => $this->definitionVersion,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'project_id' => $this->projectId,
            'result' => $this->result,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'step_no' => $this->stepNo,
        ];
    }
}
