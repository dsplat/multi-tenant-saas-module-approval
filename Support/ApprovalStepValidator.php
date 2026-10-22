<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Support;

use MultiTenantSaas\Modules\Approval\Models\ApprovalStep;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 定义步骤结构校验与归一化
 *
 * 步骤配置（steps JSON）：
 *   step_no      序号，从 1 连续
 *   name         展示名
 *   mode         any | all
 *   resolver     审批人解析器键（fixed / role / subject_owner / 宿主自定义）
 *   config       解析器参数（如 operator_ids / role / role_id）
 *   sla_hours    可选，步骤激活后多少小时到期
 */
final class ApprovalStepValidator
{
    public const RESOLVERS = ['fixed', 'role', 'subject_owner'];

    /**
     * 归一化（创建/编辑草稿用）：补齐 step_no、name、mode 形态；非法结构直接 422。
     *
     * @param  array<int, mixed>  $steps
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $steps): array
    {
        if (! array_is_list($steps)) {
            throw new UnprocessableEntityHttpException('审批步骤必须是数组列表');
        }

        $normalized = [];

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                throw new UnprocessableEntityHttpException('审批步骤必须是对象');
            }

            $stepNo = $step['step_no'] ?? $index + 1;
            if (! is_int($stepNo) && ! (is_string($stepNo) && ctype_digit($stepNo))) {
                throw new UnprocessableEntityHttpException('审批步骤序号必须是整数');
            }
            $stepNo = (int) $stepNo;

            $mode = (string) ($step['mode'] ?? ApprovalStep::MODE_ANY);
            if (! in_array($mode, ApprovalStep::MODES, true)) {
                throw new UnprocessableEntityHttpException("非法审批模式：{$mode}");
            }

            $resolver = (string) ($step['resolver'] ?? '');
            if ($resolver === '') {
                throw new UnprocessableEntityHttpException("第 {$stepNo} 步缺少审批人解析器");
            }

            $config = $step['config'] ?? [];
            if (! is_array($config)) {
                throw new UnprocessableEntityHttpException("第 {$stepNo} 步解析器参数必须是对象");
            }

            $slaHours = $this->normalizeSlaHours($step['sla_hours'] ?? null);

            $normalized[] = [
                'step_no' => $stepNo,
                'name' => (string) ($step['name'] ?? "步骤 {$stepNo}"),
                'mode' => $mode,
                'resolver' => $resolver,
                'config' => $config,
                'sla_hours' => $slaHours,
            ];
        }

        return $normalized;
    }

    /**
     * 发布前校验：至少一步、序号连续、解析器已注册、模式合法、SLA 合理。
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function validateForPublish(array $steps, ApproverResolverRegistry $resolvers): void
    {
        if ($steps === []) {
            throw new UnprocessableEntityHttpException('审批定义至少需要一个步骤');
        }

        $expected = 1;

        foreach ($steps as $step) {
            $stepNo = (int) ($step['step_no'] ?? 0);

            if ($stepNo !== $expected) {
                throw new UnprocessableEntityHttpException('审批步骤序号必须从 1 开始连续递增');
            }

            if (trim((string) ($step['name'] ?? '')) === '') {
                throw new UnprocessableEntityHttpException("第 {$stepNo} 步缺少名称");
            }

            if (! in_array($step['mode'] ?? '', ApprovalStep::MODES, true)) {
                throw new UnprocessableEntityHttpException("第 {$stepNo} 步审批模式非法");
            }

            $resolver = (string) ($step['resolver'] ?? '');
            if (! $resolvers->has($resolver)) {
                throw new UnprocessableEntityHttpException("第 {$stepNo} 步审批人解析器未注册：{$resolver}");
            }

            $this->normalizeSlaHours($step['sla_hours'] ?? null);

            $expected++;
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $settings): array
    {
        foreach (['allow_withdraw', 'allow_exemption'] as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (bool) $settings[$key];
            }
        }

        if (isset($settings['reminders']) && ! is_array($settings['reminders'])) {
            throw new UnprocessableEntityHttpException('settings.reminders 必须是数组');
        }

        return $settings;
    }

    private function normalizeSlaHours(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new UnprocessableEntityHttpException('SLA 必须是正整数小时');
        }

        $hours = (int) $value;
        $max = (int) config('approval.max_sla_hours', 8760);

        if ($hours < 1 || $hours > $max) {
            throw new UnprocessableEntityHttpException("SLA 小时数必须在 1..{$max} 之间");
        }

        return $hours;
    }
}
