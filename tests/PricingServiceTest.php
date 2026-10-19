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
            ],
            'plans' => [
                'professional_included_users' => 10,
                'professional_additional_user_annual' => 1000,
                'enterprise_included_users' => 100,
            ],
        ]);
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

    public function testEnterpriseTriggerAndNetworkedTrigger(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 37], $this->methodology());

        $this->assertSame('enterprise', $result['plan']);
        $this->assertEqualsWithDelta(2879.1667, $result['monthly_estimate'], 0.0001);
        $this->assertSame(101, $result['upgrade_trigger_users']);
        $this->assertSame(34550.0, $result['stage_1_annual_saving']);
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
            [6, 21, 630, 696, 2879, 'casual', 630, 0, 9140],
            [7, 21, 735, 696, 2879, 'professional', 696, 39, 8350],
            [8, 21, 840, 696, 2879, 'professional', 696, 144, 8350],
            [10, 21, 1050, 696, 2879, 'professional', 696, 354, 8350],
            [12, 21, 1260, 863, 2879, 'professional', 863, 398, 6350],
            [18, 21, 1890, 1363, 2879, 'professional', 1363, 528, 52750],
            [36, 21, 3780, 2863, 2879, 'professional', 2863, 918, 34750],
            [37, 21, 3885, 2946, 2879, 'enterprise', 2879, 1006, 34550],
            [60, 21, 6300, 4863, 2879, 'enterprise', 2879, 3421, 34550],
            [100, 21, 10500, 8196, 2879, 'enterprise', 2879, 7621, 34550],
            [5, 8, 200, 696, 2879, 'casual', 200, 0, 7600],
            [12, 8, 480, 863, 2879, 'casual', 480, 0, 10940],
            [20, 8, 800, 1529, 2879, 'casual', 800, 0, 59500],
            [40, 8, 1600, 3196, 2879, 'casual', 1600, 0, 49900],
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
        int $enterpriseMonthly,
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
        $this->assertSame($enterpriseMonthly, (int) round($result['enterprise_monthly']));
        $this->assertSame($plan, $result['recommended_plan']);
        $this->assertSame($recommendedMonthly, (int) round($result['monthly_estimate']));
        $this->assertSame($savingVsCasualMonthly, (int) round($result['saving_vs_casual_monthly']));
        $this->assertSame($savingVsLegacyAnnual, (int) round($result['saving_vs_legacy_annual']));
    }

    public function testNetworkedCaseHasNoPrice(): void
    {
        $result = (new PricingService())->calculate(['active_users' => 101, 'active_days' => 21], $this->methodology());

        $this->assertSame('networked', $result['recommended_plan']);
        $this->assertNull($result['monthly_estimate']);
        $this->assertNull($result['annual_estimate']);
        $this->assertFalse($result['show_savings']);
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

    public function testLowUsageCasesOfferProfessionalAfterEligibility(): void
    {
        foreach ([[12, 383], [20, 729], [40, 1596]] as [$users, $expectedDifference]) {
            $result = (new PricingService())->calculate(['active_users' => $users, 'active_days' => 8], $this->methodology());

            $this->assertSame('casual', $result['recommended_plan']);
            $this->assertSame('professional', $result['upgrade_offer']['plan']);
            $this->assertSame($expectedDifference, (int) round($result['upgrade_offer']['monthly_difference']));
        }
    }
}