<?php

namespace Eponymos\Tests;

use Eponymos\Calculation\Methodology;
use Eponymos\Pricing\PricingService;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    private function methodology(): Methodology
    {
        return new Methodology([
            'usage' => ['price_per_active_user_day' => 5, 'working_days_per_month' => 21],
            'pricing' => ['months_per_year' => 12, 'commitment_months' => 12],
            'benchmarks' => [
                'legacy_small_annual' => 10000,
                'legacy_mid_annual' => 16700,
                'legacy_enterprise_annual' => 69100,
                'legacy_small_user_limit' => 5,
                'legacy_mid_user_limit' => 15,
                'legacy_enterprise_anchor_users' => 50,
            ],
            'plans' => [
                'professional_included_users' => 10,
                'professional_additional_user_annual' => 1000,
                'enterprise_included_users' => 40,
            ],
        ]);
    }

    public function testConfiguredRateControlsCasualMonthlyPrice(): void
    {
        $methodology = new Methodology([
            'usage' => [
                'price_per_active_user_day' => 6,
                'frequency_days' => ['most_days' => 15],
            ],
        ]);
        $result = (new PricingService())->calculate([
            'active_users' => 12,
            'active_days' => $methodology->get('usage.frequency_days.most_days'),
        ], $methodology);

        $this->assertSame(1080.0, $result['casual_monthly']);
        $this->assertSame('professional', $result['plan']);
    }

    public function testBenchmarkAndCompetitorDefaultsIncludeSourceAndCheckDate(): void
    {
        $settings = Methodology::defaults();
        foreach (['legacy_small_annual', 'legacy_mid_annual', 'legacy_enterprise_annual'] as $benchmark) {
            $this->assertNotEmpty($settings['benchmarks'][$benchmark . '_source_url']);
            $this->assertNotEmpty($settings['benchmarks'][$benchmark . '_date_checked']);
        }
        foreach ($settings['competitor_costs'] as $cost) {
            $this->assertArrayHasKey('value', $cost);
            $this->assertNotEmpty($cost['source_url']);
            $this->assertNotEmpty($cost['date_checked']);
        }
        $this->assertNull((new Methodology())->validate());
    }

    public function testCasualPricingAndFirstUpgradeTrigger(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 1], $this->methodology());

        $this->assertSame('casual', $result['plan']);
        $this->assertSame(105.0, $result['monthly_estimate']);
        $this->assertSame(21.0, $result['active_user_days']);
        $this->assertSame(7, $result['upgrade_trigger_users']);
        $this->assertSame(8740.0, $result['stage_1_annual_saving']);
        $this->assertSame('casual', $result['recommended_plan']);
        $this->assertTrue($result['qualifies_professional'] === false);
        $this->assertSame('small_team', $result['legacy_segment']);
        $this->assertSame(10000.0, $result['legacy_annual_benchmark']);
        $this->assertSame('none', $result['commitment']);
    }

    public function testProfessionalTriggerAndAdditionalUserPrice(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 11], $this->methodology());

        $this->assertSame('professional', $result['plan']);
        $this->assertEqualsWithDelta(779.1667, $result['monthly_estimate'], 0.0001);
        $this->assertSame(37, $result['upgrade_trigger_users']);
        $this->assertSame(10.0, $result['included_users']);
        $this->assertEqualsWithDelta(83.3333, $result['extra_user_monthly'], 0.0001);
        $this->assertSame('12 months, billed monthly in advance', $result['commitment']);
    }

    public function testProfessionalResultIncludesCasualPriceAtSelectedUsage(): void
    {
        $result = (new PricingService())->calculate([
            'active_users' => 12,
            'active_days' => 21,
        ], $this->methodology());

        $this->assertSame('professional', $result['plan']);
        $this->assertSame(862.5, $result['monthly_estimate']);
        $this->assertSame(1260.0, $result['casual_monthly']);
    }

    public function testEnterpriseTriggerAndNetworkedTrigger(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 37], $this->methodology());

        $this->assertSame('enterprise', $result['plan']);
        $this->assertEqualsWithDelta(2879.1667, $result['monthly_estimate'], 0.0001);
        $this->assertSame(41, $result['upgrade_trigger_users']);
        $this->assertEqualsWithDelta(15087.1429, $result['stage_1_annual_saving'], 0.0001);
    }

    public function testLowUsageQualificationProducesUpgradeOfferNotSaving(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 18, 'active_days' => 8], $this->methodology());

        $this->assertSame('casual', $result['plan']);
        $this->assertTrue($result['professional_eligible']);
        $this->assertSame(1, count($result['upgrade_offers']));
        $this->assertSame('professional', $result['upgrade_offers'][0]['plan']);
        $this->assertEquals(0.0, $result['stage_1_monthly_saving_vs_casual']);
    }

    public function testExactCostTieSelectsHigherTier(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 6.626984126984127, 'active_days' => 21], $this->methodology());

        $this->assertSame('professional', $result['plan']);
    }

    /**
     * Page 8's expected-output matrix. Monetary values are rounded only for
     * display; the service keeps the underlying calculations unrounded.
     */
    public static function expectedOutputCases(): array
    {
        return [
            [1, 21, 105, 696, 2879, 'casual', 105, 0, 8740],
            [5, 21, 525, 696, 2879, 'casual', 525, 0, 3700],
            [6, 21, 630, 696, 2879, 'casual', 630, 0, 3110],
            [7, 21, 735, 696, 2879, 'professional', 696, 39, 2990],
            [8, 21, 840, 696, 2879, 'professional', 696, 144, 3660],
            [10, 21, 1050, 696, 2879, 'professional', 696, 354, 5000],
            [12, 21, 1260, 863, 2879, 'professional', 863, 398, 4340],
            [18, 21, 1890, 1363, 2879, 'professional', 1363, 528, 4841],
            [36, 21, 3780, 2863, 2879, 'professional', 2863, 918, 13790],
            [37, 21, 3885, 2946, 2879, 'enterprise', 2879, 1006, 15087],
            [60, 21, 6300, 4863, null, 'professional', 4863, 1438, 25721],
            [100, 21, 10500, 8196, null, 'professional', 8196, 2304, 45607],
            [5, 8, 200, 696, 2879, 'casual', 200, 0, 7600],
            [12, 8, 480, 863, 2879, 'casual', 480, 0, 8930],
            [20, 8, 800, 1529, 2879, 'casual', 800, 0, 14586],
            [40, 8, 1600, 3196, 2879, 'casual', 1600, 0, 34929],
        ];
    }

    /**
     * @dataProvider expectedOutputCases
     */
    public function testPageEightExpectedOutputs(
        int $users,
        int $activeDays,
        int $casualMonthly,
        int $professionalMonthly,
        ?int $enterpriseMonthly,
        string $plan,
        int $recommendedMonthly,
        int $savingVsCasualMonthly,
        int $savingVsLegacyAnnual
    ): void {
        $result = (new PricingService())->calculate([
            'active_users' => $users,
            'active_days' => $activeDays,
        ], $this->methodology());

        $this->assertSame($casualMonthly, (int) round($result['casual_monthly']));
        $this->assertSame($professionalMonthly, (int) round($result['professional_monthly']));
        if ($enterpriseMonthly === null) {
            $this->assertNull($result['enterprise_monthly']);
        } else {
            $this->assertSame($enterpriseMonthly, (int) round($result['enterprise_monthly']));
        }
        $this->assertSame($plan, $result['recommended_plan']);
        $this->assertSame($recommendedMonthly, (int) round($result['monthly_estimate']));
        $this->assertSame($savingVsCasualMonthly, (int) round($result['saving_vs_casual_monthly']));
        $this->assertSame($savingVsLegacyAnnual, (int) round($result['saving_vs_legacy_annual']));
    }

    public function testLegacyBenchmarkInterpolatesSmoothlyAndAvoidsExactHalfSavings(): void
    {
        $service = new PricingService();
        $methodology = $this->methodology();
        $previousBenchmark = null;
        $previousSaving = null;
        $previousPlan = null;

        for ($users = 1; $users <= 60; $users++) {
            $result = $service->calculate(['active_users' => $users, 'active_days' => 21], $methodology);
            $benchmark = $result['legacy_benchmark_annual'];
            $saving = $result['stage_1_annual_saving'];

            if ($previousBenchmark !== null) {
                $this->assertLessThanOrEqual(5000, $benchmark - $previousBenchmark);
            }
            if ($previousPlan === $result['plan']) {
                $this->assertLessThanOrEqual(5000, abs($saving - $previousSaving));
            }
            $this->assertNotEqualsWithDelta(0.5, $result['stage_1_annual_saving_percentage'], 0.0000001, 'Unexpected 50% savings at ' . $users . ' users.');
            $previousBenchmark = $benchmark;
            $previousSaving = $saving;
            $previousPlan = $result['plan'];
        }
    }

    public function testLegacyBenchmarkInterpolationUsesConfiguredAnchors(): void
    {
        $service = new PricingService();
        $methodology = $this->methodology();

        foreach ([5 => 10000.0, 15 => 16700.0, 50 => 69100.0] as $users => $expectedBenchmark) {
            $result = $service->calculate(['active_users' => $users, 'active_days' => 21], $methodology);
            $this->assertSame($expectedBenchmark, $result['legacy_benchmark_annual']);
        }
    }

    public function testNetworkedCaseHasNoPrice(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 501, 'active_days' => 21], $this->methodology());

        $this->assertSame('networked', $result['recommended_plan']);
        $this->assertNull($result['monthly_estimate']);
        $this->assertNull($result['annual_estimate']);
        $this->assertNull($result['price_per_active_user_day']);
        $this->assertNull($result['casual_monthly']);
        $this->assertNull($result['professional_monthly']);
        $this->assertNull($result['enterprise_monthly']);
        $this->assertNull($result['extra_user_monthly']);
        $this->assertFalse($result['show_savings']);
    }

    public function testUserCountAboveConfiguredCapRoutesToNetworkedWithoutPrice(): void
    {
        $methodology = new Methodology([
            'plans' => ['enterprise_included_users' => 1000],
        ]);
        $result = (new PricingService())->calculate([
            'active_users' => 501,
            'active_days' => 15,
            'maximum_self_serve_users' => 500,
        ], $methodology);

        $this->assertSame('networked', $result['plan']);
        $this->assertSame('user_count_above_maximum', $result['networked_reason']);
        $this->assertNull($result['monthly_estimate']);
        $this->assertNull($result['annual_estimate']);
        $this->assertNull($result['price_per_active_user_day']);
        $this->assertNull($result['casual_monthly']);
        $this->assertNull($result['professional_monthly']);
        $this->assertNull($result['enterprise_monthly']);
        $this->assertNull($result['extra_user_monthly']);
    }

    public function testOneUserOnMostDaysCostsSeventyFiveDollars(): void
    {
        $result = (new PricingService())->calculate([
            'active_users' => 1,
            'active_days' => 15,
        ], $this->methodology());

        $this->assertSame('casual', $result['plan']);
        $this->assertSame(75.0, $result['monthly_estimate']);
    }

    /**
     * @dataProvider aboveEnterpriseSeatLimitCases
     */
    public function testAboveFortyUsersKeepPricedPlansAndShowEnterpriseContact(int $users, float $casualPrice, float $professionalPrice): void
    {
        $result = (new PricingService())->calculate([
            'active_users' => $users,
            'active_days' => 4,
            'maximum_self_serve_users' => 500,
        ], $this->methodology());

        $this->assertSame('casual', $result['plan']);
        $this->assertEqualsWithDelta($casualPrice, $result['monthly_estimate'], 0.0001);
        $this->assertEqualsWithDelta($professionalPrice, $result['professional_monthly'], 0.0001);
        $this->assertNull($result['enterprise_monthly']);
        $this->assertTrue($result['enterprise_contact_required']);
        $this->assertSame('Contact us for 40+ seats', $result['enterprise_contact_label']);
        $this->assertNull($result['networked_reason']);
    }

    public static function aboveEnterpriseSeatLimitCases(): array
    {
        return [
            '41 users' => [41, 820.0, 3279.1667],
            '60 users' => [60, 1200.0, 4862.5],
        ];
    }

    /**
     * @dataProvider mostDaysEnterpriseOfferCases
     */
    public function testMostDaysOfferSelectsCheapestHigherPlan(int $users, string $plan, float $monthlyPrice, ?string $offerPlan, ?int $trigger, bool $enterpriseEligible): void
    {
        $result = (new PricingService())->calculate([
            'active_users' => $users,
            'active_days' => 15,
        ], $this->methodology());

        $this->assertSame($plan, $result['plan']);
        $this->assertEqualsWithDelta($monthlyPrice, $result['monthly_estimate'], 0.0001);
        $this->assertSame($offerPlan, $result['upgrade_offer']['plan'] ?? null);
        $this->assertSame($trigger, $result['upgrade_trigger_users']);
        $this->assertSame($enterpriseEligible, $result['enterprise_eligible']);
        if ($offerPlan !== null) {
            $this->assertCount(1, $result['upgrade_offers']);
            $this->assertSame($offerPlan, $result['next_plan']);
        }
    }

    public static function mostDaysEnterpriseOfferCases(): array
    {
        return [
            '37 users' => [37, 'casual', 2775.0, 'enterprise', 39, false],
            '38 users' => [38, 'casual', 2850.0, 'enterprise', 39, false],
            '39 users' => [39, 'enterprise', 34550 / 12, null, 41, true],
            '40 users' => [40, 'enterprise', 34550 / 12, null, 41, true],
            '12 users' => [12, 'professional', 862.5, null, 39, false],
        ];
    }

    public function testAgencyWithAtLeastTwentySixClientsIsNetworked(): void
    {
        $result = (new PricingService())->calculate([
            'active_users' => 5,
            'active_days' => 21,
            'organization_type' => 'agency',
            'clients' => 26,
        ], $this->methodology());

        $this->assertSame('networked', $result['recommended_plan']);
        $this->assertSame('agency_client_workspaces', $result['networked_reason']);
        $this->assertNull($result['monthly_estimate']);
    }

    public function testAgencyWithElevenToTwentyFiveClientsGetsNetworkedNudge(): void
    {
        $result = (new PricingService())->calculate([
            'active_users' => 5,
            'active_days' => 21,
            'organization_type' => 'agency',
            'clients' => 11,
        ], $this->methodology());

        $this->assertSame('casual', $result['recommended_plan']);
        $this->assertContains('Consider Networked for larger teams or multi-client workspaces.', $result['nudges']);
    }

    public function testLowUsageCasesOfferCheapestPlanAfterEligibility(): void
    {
        foreach ([[12, 'professional', 383], [20, 'professional', 729], [40, 'enterprise', 1279]] as [$users, $expectedPlan, $expectedDifference]) {
            $result = (new PricingService())->calculate(['active_users' => $users, 'active_days' => 8], $this->methodology());

            $this->assertSame('casual', $result['recommended_plan']);
            $this->assertSame($expectedPlan, $result['upgrade_offer']['plan']);
            $this->assertSame($expectedDifference, (int) round($result['upgrade_offer']['monthly_difference']));
        }
    }
}