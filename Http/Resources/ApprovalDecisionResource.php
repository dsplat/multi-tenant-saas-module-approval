<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;

/**
 * @mixin ApprovalDecision
 */
class ApprovalDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'decision_id' => $this->decision_id,
            'instance_id' => $this->instance_id,
            'step_id' => $this->step_id,
            'operator_id' => $this->operator_id,
            'decision' => $this->decision,
            'comment' => $this->comment,
            'source' => $this->source,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'decided_at' => $this->decided_at?->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
