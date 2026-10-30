<?php

use Illuminate\Database\Migrations\Migration;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Approval\Services\ApprovalInitiatorRepair;

return new class extends Migration
{
    public function up(): void
    {
        (new ApprovalInitiatorRepair)->repair();
    }

    public function down(): void
    {
        throw new DomainException('approval_initiator_repair_irreversible: corrected User references must not become Operator references');
    }
};
