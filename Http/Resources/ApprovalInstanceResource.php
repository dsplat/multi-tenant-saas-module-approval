<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;

/**
 * @mixin ApprovalInstance
 */
class ApprovalInstanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'instance_id' => $this->instance_id,
            'definition_id' => $this->definition_id,
            'definition_key' => $this->definition_key,
            'definition_version' => (int) $this->definition_version,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'project_id' => $this->project_id,
            'subject_snapshot' => $this->subject_snapshot ?? [],
            'definition_snapshot' => $this->definition_snapshot ?? [],
            'status' => $this->status,
            'current_step_no' => $this->current_step_no,
            'is_exempt' => (bool) $this->is_exempt,
            'exemption_reason' => $this->exemption_reason,
            'initiated_by' => $this->initiated_by,
            'previous_instance_id' => $this->previous_instance_id,
            'outcome' => $this->outcome,
            'started_at' => $this->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'steps' => ApprovalStepResource::collection($this->whenLoaded('steps')),
            'decisions' => ApprovalDecisionResource::collection($this->whenLoaded('decisions')),
        ];
    }
}
