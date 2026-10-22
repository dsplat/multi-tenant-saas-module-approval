<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Support;

/**
 * 审批主体解析结果（宿主返回值）
 *
 * 发起审批时整体冻结进 approval_instances.subject_snapshot，
 * 后续主体变更不会回写在途实例。
 */
final readonly class ApprovalSubjectResult
{
    /**
     * @param  array<string, mixed>  $attributes  展示型属性快照（金额/周期/类型等），不参与公共规则
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public int $tenantId,
        public ?string $title = null,
        public ?string $summary = null,
        public int|string|null $projectId = null,
        public ?int $ownerOperatorId = null,
        public array $attributes = [],
        public ?string $detailUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'tenant_id' => $this->tenantId,
            'title' => $this->title,
            'summary' => $this->summary,
            'project_id' => $this->projectId,
            'owner_operator_id' => $this->ownerOperatorId,
            'attributes' => $this->attributes,
            'detail_url' => $this->detailUrl,
        ];
    }
}
