<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 审批定义（含版本）
 *
 * 同一租户 (key, version) 唯一；key 是稳定业务键。发布后不可修改，
 * 修改必须复制为新版本（version + 1 的 draft）。
 */
class ApprovalDefinition extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'approval_definitions';

    protected $primaryKey = 'definition_id';

    protected $fillable = [
        'tenant_id', 'key', 'name', 'subject_type', 'version', 'status',
        'steps', 'settings', 'created_by', 'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'steps' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(ApprovalInstance::class, 'definition_id', 'definition_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
