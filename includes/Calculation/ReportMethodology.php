<?php

namespace Eponymos\Calculation;

use WP_Error;

/** Formula-only calculation service. Business assumptions are supplied by Methodology. */
class ReportMethodology
{
    public function calculate(array $input)
    {
        foreach (['current_monthly_software_cost', 'annual_periods', 'current_hours_per_pitch', 'new_hours_per_pitch', 'hourly_rate'] as $key) {
            if (!array_key_exists($key, $input) || !is_numeric($input[$key])) return new WP_Error('calculation_input_missing', 'A required calculation input is missing.', ['input' => $key, 'status' => 422]);
        }
        $periods = (float) $input['annual_periods'];
        if ($periods <= 0) return new WP_Error('invalid_calculation', 'Calculation inputs do not support a valid ROI calculation.', ['status' => 422]);

        $software = (float) $input['current_monthly_software_cost'];
        $price = isset($input['monthly_price']) && is_numeric($input['monthly_price']) ? (float) $input['monthly_price'] : null;
        $pitches = isset($input['pitches_per_month']) && is_numeric($input['pitches_per_month']) ? max(0, (float) $input['pitches_per_month']) : null;
        $currentHours = $pitches === null ? null : $pitches * (float) $input['current_hours_per_pitch'];
        $newHours = $pitches === null ? null : $pitches * (float) $input['new_hours_per_pitch'];
        $reclaimed = $currentHours === null ? null : max(0, $currentHours - $newHours);
        $monthlyProductivity = $reclaimed === null ? null : $reclaimed * (float) $input['hourly_rate'];
        $hasSoftwareBaseline = $software > 0;
        $monthlySoftwareSavings = !$hasSoftwareBaseline ? null : ($price !== null && $price > 0 ? max(0, $software - $price) : 0);
        $annualSoftware = $monthlySoftwareSavings === null ? null : $monthlySoftwareSavings * $periods;
        $annualProductivity = $monthlyProductivity === null ? null : $monthlyProductivity * $periods;
        $investment = $price !== null && $price > 0 ? $price * $periods : null;
        $total = ($annualSoftware ?? 0) + ($annualProductivity ?? 0);

        return [
            'current_monthly_software_cost' => $software,
            'monthly_software_savings' => $monthlySoftwareSavings,
            'current_monthly_hours' => $currentHours,
            'new_monthly_hours' => $newHours,
            'hours_reclaimed' => $reclaimed,
            'monthly_productivity_value' => $monthlyProductivity,
            'annual_software_savings' => $annualSoftware,
            'annual_productivity_value' => $annualProductivity,
            'annual_investment' => $investment,
            'total_annual_value' => $total,
            'roi_percent' => $investment === null || !$hasSoftwareBaseline ? null : ($total / $investment) * 100,
        ];
    }
}
