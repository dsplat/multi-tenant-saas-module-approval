<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDefinition;

/**
 * @mixin ApprovalDefinition
 */
class ApprovalDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'definition_id' => $this->definition_id,
            'key' => $this->key,
            'name' => $this->name,
            'subject_type' => $this->subject_type,
            'version' => (int) $this->version,
            'status' => $this->status,
            'steps' => $this->steps ?? [],
            'settings' => $this->settings ?? [],
            'created_by' => $this->created_by,
            'published_at' => $this->published_at?->format('Y-m-d H:i:s'),
            'archived_at' => $this->archived_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
