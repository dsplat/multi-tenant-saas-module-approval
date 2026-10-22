<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;
use MultiTenantSaas\Modules\Approval\Services\ApprovalQueryService;
use MultiTenantSaas\Modules\Approval\Services\Tools\Concerns\ResolvesOperatorIdentity;

/**
 * AI 工具：审批意见草稿
 *
 * 只生成文本，绝不落库、绝不调用决策接口。返回 can_submit 仅供前端提示，
 * 真正的提交仍必须由审批人本人经 decision.submit 能力完成。
 */
class ApprovalDecisionDraftHandler implements ToolHandlerContract
{
    use ResolvesOperatorIdentity;

    public function __construct(private readonly ApprovalQueryService $queries) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $operatorId = $this->currentOperatorId();

        if ($operatorId === null) {
            return ['error' => '缺少 operator 身份，无法生成意见草稿'];
        }

        $instanceId = $arguments['instance_id'] ?? null;
        if ($instanceId === null || $instanceId === '') {
            return ['error' => '缺少 instance_id'];
        }

        $decision = (string) ($arguments['decision'] ?? ApprovalDecision::DECISION_APPROVE);
        if (! in_array($decision, ApprovalDecision::DECISIONS, true)) {
            return ['error' => 'decision 必须是 approve 或 reject'];
        }

        $notes = trim((string) ($arguments['notes'] ?? ''));

        try {
            $summary = $this->queries->summary($tenantId, $instanceId, $operatorId);
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }

        $title = $summary['subject']['title'] ?? $summary['subject']['id'];
        $currentStep = '（当前无活动步骤）';

        foreach ($summary['steps'] as $step) {
            if ($step['status'] === 'active') {
                $currentStep = $step['name'];
                break;
            }
        }

        $label = $decision === ApprovalDecision::DECISION_APPROVE ? '通过' : '驳回';
        $body = $notes !== '' ? $notes : '（请补充具体意见）';

        $draft = "审批事项：{$title}\n"
            . "当前步骤：{$currentStep}\n"
            . "拟审批结论：{$label}\n"
            . "意见草稿：{$body}\n\n"
            . '提示：以上仅为草稿，需由审批人本人确认后提交，系统不会自动审批。';

        return [
            'instance_id' => $summary['instance_id'],
            'decision' => $decision,
            'draft' => $draft,
            'is_draft' => true,
            'auto_submitted' => false,
        ];
    }
}
