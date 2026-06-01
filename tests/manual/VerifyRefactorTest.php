<?php

/**
 * Pest Test - Unified Resource-Based Booking Architecture Verification
 * Run with: vendor/bin/pest tests/manual/VerifyRefactorTest.php
 */
uses(Tests\TestCase::class);

global $wpdb;
$table = 'wp_sb_bookings';

// Test A: Multi-Space Booking Creation
test('Test A: Creates two distinct rows for multi-space booking', function () use ($wpdb, $table) {
    // Cleanup first
    $wpdb->query("DELETE FROM $table WHERE booking_date = '2026-05-20' AND space_id IN (101, 102, 103)");

    $repo = new \SpaceBooking\Services\BookingRepository();

    // Create lead booking
    $lead_id = $repo->create([
        'space_id' => 101,
        'customer_name' => 'Test Customer',
        'customer_email' => 'test@example.com',
        'booking_date' => '2026-05-20',
        'start_time' => '14:00',
        'end_time' => '15:00',
        'status' => 'in_review',
    ]);

    // Create secondary row
    $secondary_id = $repo->create_booking_row([
        'space_id' => 102,
        'order_id' => $lead_id,
        'booking_date' => '2026-05-20',
        'start_time' => '14:00',
        'end_time' => '15:00',
        'status' => 'in_review',
    ]);

    // Verify
    $rows = $wpdb->get_results("SELECT space_id, status FROM $table WHERE booking_date = '2026-05-20' AND space_id IN (101, 102)", ARRAY_A);

    expect(count($rows))->toBe(2);
    expect(in_array(101, array_column($rows, 'space_id')))->toBeTrue();
    expect(in_array(102, array_column($rows, 'space_id')))->toBeTrue();
});

// Test B: Single-Space Detection
test('Test B: Single space query detects bookings for that space', function () use ($wpdb, $table) {
    $repo = new \SpaceBooking\Services\BookingRepository();

    $blocking = $repo->get_blocking_intervals([101], '2026-05-20');

    expect(count($blocking))->toBeGreaterThan(0);
    expect($blocking[0]['start'])->toBe('14:00');
    expect($blocking[0]['end'])->toBe('15:00');
});

// Test C: Common Slot Intersection
test('Test C: Intersection excludes slots when one space is booked', function () {
    $availability = new \SpaceBooking\Services\AvailabilityService();

    $slots = $availability->generate_dynamic_slots([101, 103], '2026-05-20', 60);

    // Find 14:00 slot
    $found_14_available = false;
    foreach ($slots as $slot) {
        if ($slot['start'] === '14:00' && $slot['available']) {
            $found_14_available = true;
            break;
        }
    }

    expect($found_14_available)->toBeFalse();  // 14:00 should be blocked
});

// Test D: Conflict Messaging includes reason
test('Test D: Blockers include message with space name', function () {
    $availability = new \SpaceBooking\Services\AvailabilityService();

    $intersection = $availability->get_intersection_slots([101, 102], '2026-05-20', 60);

    expect(count($intersection['blockers']))->toBeGreaterThan(0);
    expect(isset($intersection['blockers'][0]['message']))->toBeTrue();
    expect(str_contains($intersection['blockers'][0]['message'], 'Reason:'))->toBeTrue();
});
