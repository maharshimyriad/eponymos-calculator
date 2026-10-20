<?php

namespace Eponymos\Report;

class ReportEngine
{
    public function build($submission)
    {
        $outputs = $submission['calculation_outputs'] ?? [];
        $pricing = $submission['pricing_result'] ?? [];
        $is_networked = ($pricing['plan'] ?? '') === 'networked';

        $sections = [];

        // --- Savings section ---
        if (!empty($outputs) && $is_networked) {
            $sections[] = [
                'title' => 'Current Software Spend',
                'rows' => [
                    ['label' => 'Current software spend', 'value' => $this->fmt_currency($outputs['current_monthly_software_cost'] ?? null)],
                ],
            ];
        } elseif (!empty($outputs)) {
            $sections[] = [
                'title'  => 'Annual Software Savings',
                'rows'   => [
                    ['label' => 'Annual software savings',    'value' => $this->fmt_currency($outputs['annual_software_savings'] ?? null)],
                    ['label' => 'Current software spend',    'value' => $this->fmt_currency($outputs['current_monthly_software_cost'] ?? null)],
                ],
            ];
        }

        // --- Pricing section ---
        if (!empty($pricing)) {
            $sections[] = [
                'title' => 'Usage-Based Pricing Estimate',
                'rows'  => [
                    ['label' => 'Pricing model',          'value' => $is_networked ? 'Networked (custom pricing)' : ($pricing['model'] ?? '—')],
                    ['label' => 'Active user days',       'value' => $this->fmt_number($pricing['active_user_days'] ?? null)],
                    ['label' => 'Rate per active user day', 'value' => $this->fmt_currency($pricing['price_per_active_user_day'] ?? null)],
                    ['label' => 'Monthly estimate',       'value' => $is_networked ? 'Contact us for pricing' : $this->fmt_currency($pricing['monthly_estimate'] ?? null)],
                    ...($is_networked ? [['label' => 'Contact', 'value' => '/contact-us/']] : []),
                ],
            ];
        }

        // --- Qualitative insights ---
        $qualitative = $this->qualitative_insights($submission);

        return [
            'sections'             => $sections,
            'qualitative'          => $qualitative,
            'calculation_snapshot' => $outputs,
        ];
    }

    private function qualitative_insights($submission): array
    {
        $insights = [];
        $answers = $submission['resolved_values'] ?? [];

        $org = $answers['organization_type'] ?? null;
        if ($org) $insights[] = ['key' => 'organization_type', 'message' => 'Based on your profile as a ' . $org . ', Eponymos is calibrated for your workflow.'];

        $channels = $answers['publish_channels'] ?? [];
        if (is_array($channels) && count($channels) >= 3) {
            $insights[] = ['key' => 'multi_channel', 'message' => 'You publish across ' . count($channels) . ' channels — Eponymos\' multi-channel distribution can save significant manual effort.'];
        }

        $tools = $answers['current_tools'] ?? [];
        if (is_array($tools) && in_array('Nothing formal / manual', $tools, true)) {
            $insights[] = ['key' => 'no_formal_tools', 'message' => 'Moving from manual processes to Eponymos typically delivers the highest productivity gains.'];
        }

        return $insights;
    }

    private function fmt_number($value): string
    {
        if ($value === null) return '—';
        return number_format((float)$value, 1);
    }

    private function fmt_currency($value): string
    {
        if ($value === null) return '—';
        return '$' . number_format((float)$value, 2);
    }
}
