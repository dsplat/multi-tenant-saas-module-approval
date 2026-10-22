<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalStep;

/**
 * @mixin ApprovalStep
 */
class ApprovalStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'step_id' => $this->step_id,
            'instance_id' => $this->instance_id,
            'step_no' => (int) $this->step_no,
            'name' => $this->name,
            'mode' => $this->mode,
            'status' => $this->status,
            'approvers_snapshot' => $this->approvers_snapshot ?? [],
            'activated_at' => $this->activated_at?->format('Y-m-d H:i:s'),
            'due_at' => $this->due_at?->format('Y-m-d H:i:s'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i:s'),
            'blocked_reason' => $this->blocked_reason,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
