<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Services/DateDisplayHelper.php';

final class DateDisplayHelperTest extends TestCase
{
    public function test_formats_iso_booking_dates_in_long_form(): void
    {
        $this->assertSame(
            'June 2, 2026',
            \SpaceBooking\Services\DateDisplayHelper::format_booking_date('2026-06-02')
        );
    }

    public function test_returns_input_for_invalid_dates(): void
    {
        $this->assertSame(
            'not-a-date',
            \SpaceBooking\Services\DateDisplayHelper::format_booking_date('not-a-date')
        );
    }
}
