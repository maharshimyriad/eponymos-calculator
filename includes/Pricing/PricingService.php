<?php

namespace Eponymos\Pricing;

use Eponymos\Calculation\Methodology;
use WP_Error;

class PricingService
{
    public function calculate($inputs, ?Methodology $methodology = null)
    {
        $methodology = $methodology ?: Methodology::load();
        $users = isset($inputs['active_users']) && is_numeric($inputs['active_users']) ? (float) $inputs['active_users'] : 0.0;
        $working_days = isset($inputs['active_days']) && is_numeric($inputs['active_days'])
            ? (float) $inputs['active_days']
            : (float) $methodology->get('usage.working_days_per_month');
        $unit = $methodology->get('usage.price_per_active_user_day');
        $months = (float) $methodology->get('pricing.months_per_year');
        if ($working_days <= 0 || $unit === null || (float) $unit < 0 || $months <= 0) {
            return new WP_Error('pricing_configuration_missing', 'Pricing methodology is incomplete.', ['status' => 500]);
        }

        $tierPercent = (float) $methodology->get('pricing.tier_price_percent_of_legacy');
        $professionalAnnual = (float) $methodology->get('benchmarks.legacy_mid_annual') * $tierPercent;
        $professionalIncluded = (float) $methodology->get('plans.professional_included_users');
        $professionalAdditional = (float) $methodology->get('plans.professional_additional_user_annual');
        $enterpriseAnnual = (float) $methodology->get('benchmarks.legacy_enterprise_annual') * $tierPercent;
        $enterpriseIncluded = (float) $methodology->get('plans.enterprise_included_users');
        $casualTrigger = (int) floor($professionalAnnual / ((float) $unit * $working_days * $months)) + 1;
        $enterpriseTrigger = (int) $professionalIncluded + (int) floor(($enterpriseAnnual - $professionalAnnual) / $professionalAdditional) + 1;
        $networkedTrigger = (int) $enterpriseIncluded + 1;
        $professionalOfferTrigger = (int) floor($professionalAnnual / ((float) $unit * (float) $methodology->get('usage.working_days_per_month') * $months)) + 1;
        $activeUserDays = $users * $working_days;
        $casualMonthly = $activeUserDays * (float) $unit;
        $professionalMonthly = ($professionalAnnual + max(0, $users - $professionalIncluded) * $professionalAdditional) / $months;
        $enterpriseMonthly = $enterpriseAnnual / $months;
        $isAgency = strtolower((string) ($inputs['organization_type'] ?? '')) === 'agency';
        $clients = isset($inputs['clients']) && is_numeric($inputs['clients']) ? (float) $inputs['clients'] : (isset($inputs['client_count']) && is_numeric($inputs['client_count']) ? (float) $inputs['client_count'] : 0.0);
        $networkedReason = $users >= $networkedTrigger ? 'enterprise_user_limit' : (($isAgency && $clients >= 26) ? 'agency_client_workspaces' : null);
        $networked = $networkedReason !== null;

        $planCosts = ['casual' => $casualMonthly, 'professional' => $professionalMonthly, 'enterprise' => $enterpriseMonthly];
        $lowestCost = min($planCosts);
        $recommendedPlan = 'casual';
        $planRanks = ['casual' => 1, 'professional' => 2, 'enterprise' => 3];
        foreach ($planCosts as $candidate => $cost) {
            if ($cost < $lowestCost || (abs($cost - $lowestCost) < 0.000001 && $planRanks[$candidate] > $planRanks[$recommendedPlan])) {
                $recommendedPlan = $candidate;
            }
        }
        $plan = $networked ? 'networked' : $recommendedPlan;
        $monthlyPrice = $networked ? null : $planCosts[$plan];
        $professionalEligible = $users >= $casualTrigger || $activeUserDays >= $professionalAnnual / (float) $unit / $months;
        $enterpriseEligible = $users >= $enterpriseTrigger;
        $upgradeOffers = [];
        if (!$networked) {
            $professionalOfferEligible = $professionalEligible || $users >= $professionalOfferTrigger;
            foreach ([['professional', $professionalOfferEligible], ['enterprise', $enterpriseEligible]] as [$upgradePlan, $eligible]) {
                if ($eligible && $planRanks[$upgradePlan] > $planRanks[$plan] && $planCosts[$upgradePlan] > $monthlyPrice) {
                    $upgradeOffers[] = ['plan' => $upgradePlan, 'monthly_difference' => $planCosts[$upgradePlan] - $monthlyPrice, 'message' => ucfirst($upgradePlan) . ' is available as an optional upgrade for additional support, training and security.'];
                }
            }
        }
        $nextPlan = $plan === 'casual' ? 'professional' : ($plan === 'professional' ? 'enterprise' : ($plan === 'enterprise' ? 'networked' : null));
        $upgradeTrigger = $plan === 'casual' ? $casualTrigger : ($plan === 'professional' ? $enterpriseTrigger : ($plan === 'enterprise' ? $networkedTrigger : null));

        $legacySmallLimit = (float) $methodology->get('benchmarks.legacy_small_user_limit');
        $legacyMidLimit = (float) $methodology->get('benchmarks.legacy_mid_user_limit');
        if ($users <= $legacySmallLimit) {
            $legacyAnnual = (float) $methodology->get('benchmarks.legacy_small_annual');
            $legacySource = 'Legacy benchmark: small team (1-5 users)';
        } elseif ($users <= $legacyMidLimit) {
            $legacyAnnual = (float) $methodology->get('benchmarks.legacy_mid_annual');
            $legacySource = 'Legacy benchmark: mid-market (6-15 users)';
        } else {
            $legacyAnnual = (float) $methodology->get('benchmarks.legacy_enterprise_annual');
            $legacySource = 'Legacy benchmark: enterprise (16+ users)';
        }
        $annualPrice = is_numeric($monthlyPrice) ? (float) $monthlyPrice * $months : null;
        $legacySaving = $annualPrice === null ? null : max(0, $legacyAnnual - $annualPrice);
        $casualSaving = $monthlyPrice === null ? null : max(0, $casualMonthly - $monthlyPrice);
        $nudges = [];
        if ($plan === 'casual' && $casualTrigger - $users <= 2 && $casualTrigger > $users) {
            $nudges[] = 'You are ' . (int) ($casualTrigger - $users) . ' users away from Professional.';
        }
        if ($plan === 'professional' && $enterpriseTrigger - $users <= 5 && $enterpriseTrigger > $users) {
            $nudges[] = 'At ' . $enterpriseTrigger . ' users, Enterprise costs less than Professional plus extra users.';
        }
        if (($plan === 'enterprise' && $users >= $enterpriseIncluded - 10) || ($isAgency && $clients >= 11 && $clients <= 25)) {
            $nudges[] = 'Consider Networked for larger teams or multi-client workspaces.';
        }
        $includedUsers = $plan === 'professional' ? $professionalIncluded : ($plan === 'enterprise' ? $enterpriseIncluded : 0);
        $extraUsers = $includedUsers > 0 ? max(0, $users - $includedUsers) : 0;
        $upgradeOffer = !empty($upgradeOffers) ? [
            'plan' => $upgradeOffers[0]['plan'],
            'monthly_price' => $planCosts[$upgradeOffers[0]['plan']],
            'monthly_difference' => $upgradeOffers[0]['monthly_difference'],
        ] : null;
        $usersToNextTier = 0;
        if ($plan === 'casual' && $casualTrigger - $users >= 1 && $casualTrigger - $users <= 2) {
            $usersToNextTier = $casualTrigger - $users;
        } elseif ($plan === 'professional' && $enterpriseTrigger - $users >= 1 && $enterpriseTrigger - $users <= 5) {
            $usersToNextTier = $enterpriseTrigger - $users;
        } elseif ($plan === 'enterprise' && $enterpriseIncluded - $users >= 0 && $enterpriseIncluded - $users <= 10) {
            $usersToNextTier = $enterpriseIncluded - $users;
        }
        $legacySegment = $users <= $legacySmallLimit ? 'small_team' : ($users <= $legacyMidLimit ? 'mid_market' : 'enterprise');
        $showSavings = (($casualSaving ?? 0) > 0) || (($legacySaving ?? 0) > 0);
        $commitment = in_array($plan, ['professional', 'enterprise', 'networked'], true) ? '12 months, billed monthly in advance' : 'none';

        return [
            'model' => 'tiered_usage',
            'plan' => $plan,
            'recommended_plan' => $plan,
            'active_users' => $users,
            'billable_users' => $users,
            'active_user_days' => $activeUserDays,
            'price_per_active_user_day' => (float) $unit,
            'working_days_per_month' => $working_days,
            'months_per_year' => $months,
            'monthly_estimate' => is_numeric($monthlyPrice) ? (float) $monthlyPrice : null,
            'annual_estimate' => $annualPrice,
            'legacy_benchmark_annual' => $legacyAnnual,
            'legacy_benchmark_source' => $legacySource,
            'casual_monthly' => $casualMonthly,
            'professional_monthly' => $professionalMonthly,
            'enterprise_monthly' => $enterpriseMonthly,
            'included_users' => $includedUsers,
            'extra_users' => $extraUsers,
            'extra_user_monthly' => $plan === 'professional' ? $professionalAdditional / $months : 0,
            'professional_eligible' => $professionalEligible,
            'enterprise_eligible' => $enterpriseEligible,
            'qualifies_professional' => $professionalEligible,
            'qualifies_enterprise' => $enterpriseEligible,
            'networked_reason' => $networkedReason,
            'upgrade_offer' => $upgradeOffer,
            'upgrade_offers' => $upgradeOffers,
            'users_to_next_tier' => $usersToNextTier,
            'stage_1_monthly_saving_vs_casual' => $casualSaving,
            'stage_1_annual_saving_vs_casual' => $casualSaving === null ? null : $casualSaving * $months,
            'stage_1_annual_saving' => $legacySaving,
            'stage_1_annual_saving_percentage' => $legacySaving === null || $legacyAnnual <= 0 ? null : $legacySaving / $legacyAnnual,
            'saving_vs_casual_monthly' => ($casualSaving ?? 0) > 0 ? $casualSaving : 0,
            'saving_vs_casual_annual' => ($casualSaving ?? 0) > 0 ? $casualSaving * $months : 0,
            'legacy_segment' => $legacySegment,
            'legacy_annual_benchmark' => $legacyAnnual,
            'saving_vs_legacy_annual' => ($legacySaving ?? 0) > 0 ? $legacySaving : 0,
            'saving_vs_legacy_percent' => ($legacySaving ?? 0) > 0 && $legacyAnnual > 0 ? $legacySaving / $legacyAnnual : 0,
            'show_savings' => $showSavings,
            'commitment' => $commitment,
            'upgrade_trigger_users' => $upgradeTrigger,
            'next_plan' => $nextPlan,
            'nudges' => $nudges,
            'range' => ['min' => $monthlyPrice, 'max' => $monthlyPrice],
        ];
    }
}
