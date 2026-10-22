<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Context\TrustedExecutionInput;
use MultiTenantSaas\Contracts\ExecutionIdentityContract;
use MultiTenantSaas\Modules\Approval\Contracts\ApprovalServiceContract;

/**
 * 审批到期扫描
 *
 * 用法：
 *   php artisan approval:expire --tenant=123
 *   php artisan approval:expire --tenant=123 --dry-run
 *   php artisan approval:expire            # 扫描所有存在审批实例的租户
 *
 * 每个租户在独立的 service 执行身份 + TenantContext 下运行，
 * 保证 TenantScope 与 assertTenant 三方一致。
 */
class ExpireApprovalInstances extends Command
{
    protected $signature = 'approval:expire
        {--tenant= : 仅处理指定租户 ID}
        {--dry-run : 只统计到期数量，不写状态}';

    protected $description = '扫描到期审批步骤并把实例置为 expired';

    public function handle(ApprovalServiceContract $service, ExecutionIdentityContract $identity): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantOption = $this->option('tenant');

        if ($tenantOption !== null && $tenantOption !== '') {
            if (! ctype_digit((string) $tenantOption) || (int) $tenantOption <= 0) {
                $this->error('--tenant 必须是正整数租户 ID');

                return self::FAILURE;
            }

            $tenants = [(int) $tenantOption];
        } else {
            $tenants = DB::table('approval_instances')
                ->distinct()
                ->orderBy('tenant_id')
                ->pluck('tenant_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();
        }

        if ($tenants === []) {
            $this->info('没有需要扫描的租户。');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::getId();
        $total = 0;

        try {
            foreach ($tenants as $tenantId) {
                TenantContext::setTenantId((string) $tenantId);

                $count = $identity->runRoot(
                    new TrustedExecutionInput(new ExecutionIdentity(
                        type: ExecutionIdentity::TYPE_SERVICE,
                        surface: ExecutionIdentity::SURFACE_SYSTEM,
                        subjectId: 'approval-expire',
                        tenantId: (string) $tenantId,
                        level: ExecutionIdentity::LEVEL_AUTHENTICATED,
                        authSource: 'cli',
                    )),
                    fn (): int => $service->expireDue($tenantId, $dryRun),
                );

                $total += $count;
                $this->line(sprintf(
                    'tenant %d: %s %d instance(s)',
                    $tenantId,
                    $dryRun ? 'would expire' : 'expired',
                    $count,
                ));
            }
        } finally {
            if ($previousTenant !== null && $previousTenant !== '') {
                TenantContext::setTenantId($previousTenant);
            } else {
                TenantContext::clear();
            }
        }

        $this->info(sprintf(
            '%s: %d instance(s)',
            $dryRun ? 'Dry run completed' : 'Expiry completed',
            $total,
        ));

        return self::SUCCESS;
    }
}
