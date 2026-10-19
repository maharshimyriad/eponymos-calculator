<?php

namespace Eponymos\Tests;

use Eponymos\Calculation\ReportMethodology;
use PHPUnit\Framework\TestCase;

class ReportMethodologyTest extends TestCase
{
    public function testCalculatesReclaimedHoursProductivityAndRoi(): void
    {
        $result = (new ReportMethodology())->calculate([
            'current_monthly_software_cost' => 1250,
            'monthly_price' => 863,
            'annual_periods' => 12,
            'pitches_per_month' => 10,
            'current_hours_per_pitch' => 3,
            'new_hours_per_pitch' => 1.2,
            'hourly_rate' => 65,
        ]);

        $this->assertSame(30.0, $result['current_monthly_hours']);
        $this->assertEqualsWithDelta(12.0, $result['new_monthly_hours'], 0.0001);
        $this->assertEqualsWithDelta(18.0, $result['hours_reclaimed'], 0.0001);
        $this->assertEqualsWithDelta(1170.0, $result['monthly_productivity_value'], 0.0001);
        $this->assertEqualsWithDelta(14040.0, $result['annual_productivity_value'], 0.0001);
        $this->assertSame(18684.0, $result['total_annual_value']);
        $this->assertEqualsWithDelta(180.4171, $result['roi_percent'], 0.0001);
    }

    public function testLeavesProductivityUnavailableWithoutPitchVolume(): void
    {
        $result = (new ReportMethodology())->calculate([
            'current_monthly_software_cost' => 1250,
            'monthly_price' => 863,
            'annual_periods' => 12,
            'current_hours_per_pitch' => 3,
            'new_hours_per_pitch' => 1.2,
            'hourly_rate' => 65,
        ]);

        $this->assertNull($result['hours_reclaimed']);
        $this->assertNull($result['annual_productivity_value']);
        $this->assertSame(4644.0, $result['total_annual_value']);
    }
}