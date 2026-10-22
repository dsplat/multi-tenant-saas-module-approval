<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Support;

use MultiTenantSaas\Modules\Approval\Contracts\ApprovalSubjectContract;

/**
 * 主体契约注册表（宿主注册 subject_type → 契约实现）
 */
final class ApprovalSubjectRegistry
{
    /** @var array<string, ApprovalSubjectContract> */
    private array $contracts = [];

    public function register(ApprovalSubjectContract $contract): void
    {
        $type = trim($contract->subjectType());

        if ($type === '') {
            throw new \InvalidArgumentException('审批主体类型不得为空');
        }

        // last-wins：宿主可覆盖默认实现
        $this->contracts[$type] = $contract;
    }

    public function has(string $subjectType): bool
    {
        return isset($this->contracts[$subjectType]);
    }

    public function resolve(string $subjectType, int $tenantId, string $subjectId): ?ApprovalSubjectResult
    {
        $contract = $this->contracts[$subjectType] ?? null;

        return $contract?->resolve($tenantId, $subjectId);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->contracts);
        sort($keys);

        return $keys;
    }
}
