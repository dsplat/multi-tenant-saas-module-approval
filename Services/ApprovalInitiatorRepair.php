<?php

namespace MultiTenantSaas\Modules\Approval\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Exceptions\ConflictException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use Throwable;

final class ApprovalInitiatorRepair
{
    public function preview(?int $tenantId, int $detailLimit = 0): array
    {
        return $this->scan($tenantId, null, $detailLimit);
    }

    public function repair(): array
    {
        $batch = bin2hex(random_bytes(16));

        try {
            $report = DB::transaction(function () use ($batch): array {
                $report = $this->scan(null, $batch);
                if ($report['blockers'] > 0) {
                    throw new ConflictException('approval_initiator_repair_blocked: ' . $report['blockers'] . ' ambiguous relation(s)');
                }

                return $report;
            });
        } catch (Throwable $exception) {
            Log::warning('approval_initiator_repair_rolled_back', ['batch' => $batch]);

            throw $exception;
        }

        // Only the outermost commit makes the batch's tentative row events effective.
        DB::afterCommit(function () use ($batch, $report): void {
            Log::info('approval_initiator_repair_committed', ['batch' => $batch] + $report);
        });

        return $report;
    }

    private function scan(?int $tenantId, ?string $batch, int $detailLimit = 0): array
    {
        $report = ['scanned' => 0, 'candidates' => 0, 'unchanged' => 0, 'blockers' => 0];
        if ($detailLimit > 0) {
            $report['blocker_details'] = [];
        }
        if (! Schema::hasTable('approval_instances') || ! Schema::hasColumn('approval_instances', 'initiated_by_type')) {
            return $report;
        }

        if (! Schema::hasTable('matters') || ! Schema::hasTable('matter_participants')) {
            throw new ServiceUnavailableException('approval_initiator_repair_schema: required Matter tables are missing');
        }

        DB::table('matters')
            ->select(['matter_id', 'tenant_id', 'domain_ref_id'])
            ->where('matter_type', 'approval')
            ->where('domain_ref_type', 'approval_instance')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->chunkById(200, function ($matters) use (&$report, $batch, $detailLimit): void {
                foreach ($matters as $matter) {
                    if ($batch !== null) {
                        $locked = DB::table('matters')
                            ->select(['matter_id', 'tenant_id', 'domain_ref_id'])
                            ->where('matter_id', $matter->matter_id)
                            ->where('matter_type', 'approval')
                            ->where('domain_ref_type', 'approval_instance')
                            ->lockForUpdate()
                            ->first();
                        if ($locked === null) {
                            $this->block($report, $detailLimit, 'matter_missing', $matter, null);

                            continue;
                        }
                        $matter = $locked;
                    }

                    $report['scanned']++;
                    $participants = DB::table('matter_participants')
                        ->select(['matter_participant_id', 'actor_type', 'actor_id', 'granted_at', 'revoked_at', 'created_at', 'updated_at'])
                        ->where('matter_id', $matter->matter_id)
                        ->where('role', 'initiator')
                        ->when($batch !== null, fn ($query) => $query->lockForUpdate())
                        ->get();
                    if ($participants->isEmpty()) {
                        $report['unchanged']++;

                        continue;
                    }

                    $referenceId = (string) $matter->domain_ref_id;
                    $canonicalReference = ctype_digit($referenceId) && (string) (int) $referenceId === $referenceId && (int) $referenceId > 0;
                    $instance = $canonicalReference
                        ? DB::table('approval_instances')
                            ->select(['instance_id', 'tenant_id', 'initiated_by_type', 'initiated_by'])
                            ->where('instance_id', (int) $referenceId)
                            ->where('tenant_id', $matter->tenant_id)
                            ->when($batch !== null, fn ($query) => $query->lockForUpdate())
                            ->first()
                        : null;
                    if ($instance === null) {
                        $this->block($report, $detailLimit, $canonicalReference ? 'instance_missing_in_tenant' : 'domain_reference_not_canonical', $matter, null);

                        continue;
                    }

                    if (! in_array($instance->initiated_by_type, ['user', 'operator'], true)) {
                        $this->block($report, $detailLimit, 'initiator_type_unknown', $matter, $instance);

                        continue;
                    }

                    if ($instance->initiated_by_type === 'operator') {
                        $report['unchanged']++;

                        continue;
                    }

                    $participant = $participants->first();
                    if ($participants->count() !== 1
                        || (string) $participant->actor_id !== (string) $instance->initiated_by
                        || ! in_array($participant->actor_type, ['user', 'operator'], true)) {
                        $this->block($report, $detailLimit, 'initiator_relation_ambiguous', $matter, $instance);

                        continue;
                    }

                    if ($participant->actor_type === 'user') {
                        $report['unchanged']++;

                        continue;
                    }

                    $report['candidates']++;
                    if ($batch === null) {
                        continue;
                    }

                    DB::table('matter_participants')
                        ->where('matter_participant_id', $participant->matter_participant_id)
                        ->update(['actor_type' => 'user']);
                    // Row events are tentative until the matching batch commit event exists.
                    Log::info('approval_initiator_repair_row', [
                        'batch' => $batch,
                        'tenant_id' => (int) $matter->tenant_id,
                        'instance_id' => (int) $instance->instance_id,
                        'matter_id' => (int) $matter->matter_id,
                        'matter_participant_id' => (int) $participant->matter_participant_id,
                        'before_type' => 'operator',
                        'after_type' => 'user',
                        'actor_id' => (int) $participant->actor_id,
                        'granted_at' => $participant->granted_at,
                        'revoked_at' => $participant->revoked_at,
                        'created_at' => $participant->created_at,
                        'updated_at' => $participant->updated_at,
                    ]);
                }
            }, 'matter_id');

        return $report;
    }

    private function block(array &$report, int $detailLimit, string $reason, object $matter, ?object $instance): void
    {
        $report['blockers']++;
        if ($detailLimit <= 0 || count($report['blocker_details']) >= $detailLimit) {
            return;
        }

        // Identity metadata only: no subject/definition snapshot or business text.
        $report['blocker_details'][] = [
            'reason' => $reason,
            'tenant_id' => (int) $matter->tenant_id,
            'matter_id' => (int) $matter->matter_id,
            'instance_id' => $instance === null ? null : (int) $instance->instance_id,
        ];
    }
}
