<?php

namespace Eponymos\Tests;

use Eponymos\Report\ReportEngine;
use PHPUnit\Framework\TestCase;

class ReportEngineTest extends TestCase
{
    public function testNetworkedReportDoesNotShowUnpricedSavings(): void
    {
        $report = (new ReportEngine())->build([
            'calculation_outputs' => [
                'annual_software_savings' => 0,
                'current_monthly_software_cost' => 1250,
            ],
            'pricing_result' => [
                'plan' => 'networked',
                'model' => 'tiered_usage',
                'monthly_estimate' => null,
            ],
        ]);

        $this->assertCount(2, $report['sections']);
        $this->assertSame('Current Software Spend', $report['sections'][0]['title']);
        $this->assertSame('Usage-Based Pricing Estimate', $report['sections'][1]['title']);
        $pricing_rows = $report['sections'][1]['rows'];
        $this->assertSame('Networked (custom pricing)', $pricing_rows[0]['value']);
        $this->assertSame('Contact us for pricing', $pricing_rows[3]['value']);
        $this->assertSame('/contact-us/', $pricing_rows[4]['value']);
    }
}