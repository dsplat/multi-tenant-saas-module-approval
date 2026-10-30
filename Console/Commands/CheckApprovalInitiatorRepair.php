<?php

namespace MultiTenantSaas\Modules\Approval\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Approval\Services\ApprovalInitiatorRepair;

class CheckApprovalInitiatorRepair extends Command
{
    protected $signature = 'approval:initiator-repair-check
        {--tenant= : 仅检查指定租户}
        {--all-tenants : 显式检查所有租户}
        {--details= : 额外列出前 N 条阻断项的身份元数据}';

    protected $description = '只读检查审批事项发起人身份修复，不执行数据修改';

    public function handle(ApprovalInitiatorRepair $repair): int
    {
        $tenant = $this->option('tenant');
        $allTenants = (bool) $this->option('all-tenants');
        $details = $this->option('details');
        if (($tenant !== null) === $allTenants
            || ($tenant !== null && (! ctype_digit((string) $tenant) || (int) $tenant <= 0))) {
            $this->error('必须明确指定 --tenant=<正整数> 或 --all-tenants，不能同时使用。');

            return self::FAILURE;
        }

        if ($details !== null && (! ctype_digit((string) $details) || (int) $details <= 0)) {
            $this->error('--details 必须是正整数。');

            return self::FAILURE;
        }

        try {
            $report = $repair->preview($allTenants ? null : (int) $tenant, (int) $details);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['blockers'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
