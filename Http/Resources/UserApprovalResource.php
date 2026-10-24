<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Http\Resources;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MultiTenantSaas\Modules\Approval\Adapters\ApprovalMatterAdapter;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Auth\Services\HistoryAccessPolicy;

/**
 * C 端（本人发起人）审批白名单 Resource
 *
 * 冻结依据：docs/decisions/2026-10-24-identity-c2o-s0-contract-freeze.md §6.3 +
 * C2O 采用约定 v0.4 §4.2。白名单是**正向枚举**——先构造允许字段再按视图裁剪；
 * definition_snapshot / subject_snapshot、决策意见全文、审批人个人信息从未被读入
 * 返回值，不存在「忘删」的可能。title 是主体/定义的展示投影（同 S0 §6.3 的 title
 * 语义），不是快照本体。**不复用**后台全量 ApprovalInstanceResource（console 面）。
 *
 * 序列化契约（S0 §4）：数值 ID 一律十进制字符串；时间 RFC3339 UTC。
 */
final class UserApprovalResource
{
    /** 本人列表：S0 §6.3 白名单（审批域映射：title/status/outcome）+ 实例寻址 */
    public const LIST_FIELDS = [
        'instance_id',
        'definition_key',
        'title',
        'status',
        'outcome',
        'created_at',
        'updated_at',
    ];

    /** 详情 = 列表字段 + 进度与起止时间（公开时间线走独立端点） */
    public const DETAIL_FIELDS = [
        'instance_id',
        'definition_key',
        'title',
        'status',
        'outcome',
        'current_step_no',
        'started_at',
        'completed_at',
        'created_at',
        'updated_at',
        'can_withdraw',
    ];

    /**
     * 公开时间线条目：公开动作、发生时间、步骤号、结果。
     * 不携带审批人身份与决策意见（他人个人信息 / 决策意见全文禁止出 C 端）。
     */
    public const TIMELINE_FIELDS = ['action', 'occurred_at', 'step_no', 'outcome'];

    /**
     * @return array<string, mixed>
     */
    public function listItem(ApprovalInstance $instance): array
    {
        return $this->project($instance, self::LIST_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(ApprovalInstance $instance): array
    {
        return $this->project($instance, self::DETAIL_FIELDS);
    }

    /**
     * 保留窗元数据视图（C2O 缺口③，S0 §6.4）：被撤销参与人保留期内只见元数据。
     *
     * 字段按 HistoryAccessPolicy::METADATA_FIELDS 正向枚举——definition_key /
     * outcome / 进度与起止时间 / 决策意见 / 快照构造上不可达；status_display 取
     * ApprovalMatterAdapter 展示映射（与 matters 投影同源）。
     *
     * @return array<string, mixed>
     */
    public function metadataItem(ApprovalInstance $instance, ?string $matterId): array
    {
        $visible = [
            'matter_id' => $matterId,
            'matter_type' => ApprovalMatterAdapter::MATTER_TYPE,
            'title' => $this->title(
                (array) ($instance->subject_snapshot ?? []),
                (array) ($instance->definition_snapshot ?? []),
                $instance,
            ),
            'status_display' => ApprovalMatterAdapter::STATUS_DISPLAY[(string) $instance->status] ?? (string) $instance->status,
            'created_at' => $this->timestamp($instance->created_at),
            'updated_at' => $this->timestamp($instance->updated_at),
        ];

        return array_intersect_key($visible, array_flip(HistoryAccessPolicy::METADATA_FIELDS));
    }

    /**
     * 公开时间线条目（S0 §6.3「时间线」视图：公开动作；审批意见无公开/内部拆分，
     * 一律不出 C 端）。
     *
     * @return array<string, mixed>
     */
    public function timelineEntry(string $action, ?DateTimeInterface $occurredAt, ?int $stepNo = null, ?string $outcome = null): array
    {
        return [
            'action' => $action,
            'occurred_at' => $this->timestamp($occurredAt),
            'step_no' => $stepNo,
            'outcome' => $outcome,
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function project(ApprovalInstance $instance, array $allowed): array
    {
        $snapshot = (array) ($instance->subject_snapshot ?? []);
        $definition = (array) ($instance->definition_snapshot ?? []);

        $visible = [
            'instance_id' => (string) $instance->instance_id,
            'definition_key' => (string) $instance->definition_key,
            'title' => $this->title($snapshot, $definition, $instance),
            'status' => (string) $instance->status,
            'outcome' => $instance->outcome,
            'current_step_no' => $instance->current_step_no,
            'started_at' => $this->timestamp($instance->started_at),
            'completed_at' => $this->timestamp($instance->completed_at),
            'created_at' => $this->timestamp($instance->created_at),
            'updated_at' => $this->timestamp($instance->updated_at),
            'can_withdraw' => $instance->isActive(),
        ];

        return array_intersect_key($visible, array_flip($allowed));
    }

    /**
     * 展示投影：主体标题 → 定义名 → 主体 ID 退化；快照本体不出 C 端。
     */
    private function title(array $snapshot, array $definition, ApprovalInstance $instance): string
    {
        $title = trim((string) ($snapshot['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        $name = trim((string) ($definition['name'] ?? ''));

        return $name !== '' ? $name : (string) $instance->subject_id;
    }

    private function timestamp(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
