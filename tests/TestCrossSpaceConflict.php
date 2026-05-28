<?php

/**
 * Test Cross-Space Conflict - Verifies that when multiple spaces are queried,
 * the availability API properly calculates intersection and blocks
 * slots where ANY space in the group is booked.
 *
 * This test proves the "Group Intent" logic works correctly.
 */
require_once __DIR__ . '/bootstrap.php';

use SpaceBooking\Services\AvailabilityService;
use SpaceBooking\Services\BookingRepository;

class TestCrossSpaceConflict
{
    private AvailabilityService $service;
    private BookingRepository $repo;

    public function __construct()
    {
        $this->repo = new BookingRepository();
        $this->service = new AvailabilityService($this->repo);
    }

    /**
     * Run all tests
     */
    public function run(): void
    {
        $this->test_cross_space_blocking();
        $this->test_non_overlapping_check();
        $this->test_in_review_blocking();
    }

    /**
     * Test: Non-Overlapping Availability (Venn Diagram Check) - TIME SLOT SPECIFIC
     *
     * CRITICAL: This verifies time-slot specific intersection, NOT just date-specific.
     *
     * Scenario: Space A is free 9:00-12:00. Space B is free 13:00-16:00.
     * A joint booking for [A, B] should have Zero available slots (no overlapping time).
     *
     * This test creates actual bookings to set up non-overlapping availability.
     */
    public function test_non_overlapping_check(): void
    {
        echo "\n=== Test: Non-Overlapping Availability Check (TIME SLOT SPECIFIC) ===\n";

        $space_a = 10;  // Space A
        $space_b = 224;  // Space B
        $test_date = date('Y-m-d', strtotime('+5 days'));

        // Verify spaces exist
        $space_10 = get_post($space_a);
        $space_224 = get_post($space_b);

        if (!$space_10 || $space_10->post_type !== 'sb_space') {
            echo "SKIP: Space #$space_a not found\n";
            return;
        }

        if (!$space_224 || $space_224->post_type !== 'sb_space') {
            echo "SKIP: Space #$space_b not found\n";
            return;
        }

        echo "Testing TIME-SLOT SPECIFIC intersection\n";
        echo "Space A ($space_a): Morning 9:00-12:00\n";
        echo "Space B ($space_b): Afternoon 13:00-16:00\n";
        echo "Expected: Zero common slots (no overlapping time)\n\n";

        // Clean up any existing test bookings
        $this->cleanup_test_bookings($space_a, $test_date);
        $this->cleanup_test_bookings($space_b, $test_date);

        // Create booking for Space A in the morning (9:00-12:00)
        $booking_a = $this->create_booking(
            $space_a,
            $test_date,
            '09:00',
            '12:00',
            'Test Space A AM'
        );

        // Create booking for Space B in the afternoon (13:00-16:00)
        $booking_b = $this->create_booking(
            $space_b,
            $test_date,
            '13:00',
            '16:00',
            'Test Space B PM'
        );

        if (!$booking_a || !$booking_b) {
            echo "FAIL: Could not create test bookings for setup\n";
            return;
        }

        echo "Setup complete:\n";
        echo "  - Space $space_a booked: 09:00-12:00\n";
        echo "  - Space $space_b booked: 13:00-16:00\n\n";

        // Now query intersection - this is the CRITICAL test
        $result = $this->service->get_intersection_slots([$space_a, $space_b], $test_date, 60);
        $common_slots = $result['slots'] ?? [];
        $blockers = $result['blockers'] ?? [];

        echo "=== RESULTS ===\n";
        echo 'Common slots found: ' . count($common_slots) . "\n";
        echo 'Blockers found: ' . count($blockers) . "\n";

        if (count($common_slots) === 0) {
            echo "✓ PASS: Zero common slots - intersection is TIME SLOT SPECIFIC (correct!)\n";
        } else {
            echo '✗ FAIL: Found ' . count($common_slots) . " common slots - intersection logic is BROKEN!\n";
            echo "This indicates the logic is date-specific, not time-slot specific.\n";
            foreach ($common_slots as $slot) {
                echo "  - Slot {$slot['start']} - {$slot['end']}: " . (($slot['available'] ?? false) ? 'AVAILABLE' : 'BLOCKED') . "\n";
            }
        }

        // Also verify individual space availability
        echo "\n=== Individual Space Verification ===\n";

        $slots_a = $this->service->get_slots($space_a, $test_date, 60);
        $slots_b = $this->service->get_slots($space_b, $test_date, 60);

        $avail_a = array_filter($slots_a['slots'] ?? [], fn($s) => !empty($s['available']));
        $avail_b = array_filter($slots_b['slots'] ?? [], fn($s) => !empty($s['available']));

        echo "Space $space_a available slots: " . count($avail_a) . "\n";
        echo "Space $space_b available slots: " . count($avail_b) . "\n";

        // Cleanup
        $this->cleanup_booking($booking_a);
        $this->cleanup_booking($booking_b);

        echo "\n=== Non-Overlapping Time-Slot Test Complete ===\n";
    }

    /**
     * Test: In-Review Status Hard Lock
     *
     * Verifies that 'in_review' bookings block availability just like 'confirmed'
     */
    public function test_in_review_blocking(): void
    {
        echo "\n=== Test: In-Review Status Blocking ===\n";

        $space_id = 10;
        $test_date = date('Y-m-d', strtotime('+4 days'));
        $start_time = '14:00';
        $end_time = '15:00';

        echo "Creating IN_REVIEW booking for Space $space_id at $start_time - $end_time\n";

        // Create an 'in_review' booking
        $booking_id = $this->create_booking(
            $space_id,
            $test_date,
            $start_time,
            $end_time,
            'Test In-Review'
        );

        if ($booking_id) {
            // Update status to in_review
            update_post_meta($booking_id, '_sb_status', 'in_review');
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'sb_bookings',
                ['status' => 'in_review'],
                ['id' => $booking_id]
            );

            echo "Created in_review booking ID: $booking_id\n";

            // Query availability
            $result = $this->service->get_intersection_slots([$space_id], $test_date, 60);
            $slots = $result['slots'] ?? [];

            $slot_14 = null;
            foreach ($slots as $slot) {
                if ($slot['start'] === $start_time) {
                    $slot_14 = $slot;
                    break;
                }
            }

            echo '14:00 slot status: ';
            if ($slot_14 && empty($slot_14['available'])) {
                echo "BLOCKED ✓ (in_review blocks correctly)\n";
            } else {
                echo "AVAILABLE ✗ (in_review should block!)\n";
            }

            // Cleanup
            $this->cleanup_booking($booking_id);
        }

        echo "\n=== In-Review Test Complete ===\n";
    }

    /**
     * Test: Cross-Space Conflict Blocking
     *
     * 1. Insert a booking for Space 10 at 18:30
     * 2. Query availability for array [10, 224]
     * 3. Assert: 18:30 is BLOCKED even though 224 is empty
     */
    public function test_cross_space_blocking(): void
    {
        echo "\n=== Test: Cross-Space Conflict Blocking ===\n";

        $space_10_id = 10;
        $space_224_id = 224;
        $test_date = date('Y-m-d', strtotime('+2 days'));  // 2 days in future
        $start_time = '18:30';
        $end_time = '19:30';

        // First check if spaces exist
        $space_10 = get_post($space_10_id);
        $space_224 = get_post($space_224_id);

        if (!$space_10 || $space_10->post_type !== 'sb_space') {
            echo "SKIP: Space #$space_10_id not found\n";
            return;
        }

        if (!$space_224 || $space_224->post_type !== 'sb_space') {
            echo "SKIP: Space #$space_224_id not found\n";
            return;
        }

        // Clean up any existing test bookings for these spaces/date
        $this->cleanup_test_bookings($space_10_id, $test_date);
        $this->cleanup_test_bookings($space_224_id, $test_date);

        echo "Test Date: $test_date\n";
        echo "Booking Space $space_10_id at $start_time - $end_time\n";

        // Step 1: Insert a booking for Space 10 at 18:30
        $booking_id = $this->create_booking(
            $space_10_id,
            $test_date,
            $start_time,
            $end_time,
            'Test Cross-Space Conflict'
        );

        if (!$booking_id) {
            echo "FAIL: Could not create test booking\n";
            return;
        }

        echo "Created booking ID: $booking_id\n";

        // Step 2: Query availability for space array [10, 224]
        echo "\nQuerying availability for space IDs: [$space_10_id, $space_224_id]\n";

        $result = $this->service->get_intersection_slots(
            [$space_10_id, $space_224_id],
            $test_date,
            60
        );

        $slots = $result['slots'] ?? [];
        $blockers = $result['blockers'] ?? [];
        $is_intersection = $result['is_intersection'] ?? false;

        echo "\n=== Results ===\n";
        echo 'Is Intersection: ' . ($is_intersection ? 'true' : 'false') . "\n";
        echo 'Slots Found: ' . count($slots) . "\n";
        echo 'Blockers Found: ' . count($blockers) . "\n";

        // Check specifically for 18:30 slot
        $slot_18_30 = null;
        foreach ($slots as $slot) {
            if ($slot['start'] === $start_time) {
                $slot_18_30 = $slot;
                break;
            }
        }

        echo "\n=== Slot Details ===\n";
        foreach ($slots as $slot) {
            $available = $slot['available'] ?? false;
            echo "Slot {$slot['start']} - {$slot['end']}: " . ($available ? 'AVAILABLE' : 'BLOCKED') . "\n";
        }

        // Step 3: Assertions
        echo "\n=== Assertions ===\n";

        // The 18:30 slot should NOT be available because Space 10 is booked
        if ($slot_18_30 !== null) {
            if (empty($slot_18_30['available'])) {
                echo "✓ PASS: 18:30 slot is BLOCKED (as expected)\n";
            } else {
                echo "✗ FAIL: 18:30 slot shows AVAILABLE but Space 10 is booked at this time!\n";
            }
        } else {
            // Slot not found in available slots - check if it's because it was filtered out
            echo "Checking blockers...\n";

            // If blockers include space 10, that's also a pass
            $has_blocker_for_10 = false;
            foreach ($blockers as $blocker) {
                if ($blocker['id'] == $space_10_id) {
                    $has_blocker_for_10 = true;
                    echo "✓ PASS: Space 10 is listed as a blocker\n";
                    break;
                }
            }

            if (!$has_blocker_for_10) {
                echo "✗ FAIL: 18:30 slot not found and Space 10 is not identified as blocker\n";
            }
        }

        // There should be at least one blocker (Space 10 is fully booked at this time)
        if (count($blockers) > 0) {
            echo '✓ PASS: Blockers detected - ' . count($blockers) . " blocker(s)\n";
            foreach ($blockers as $blocker) {
                echo "  - Blocked by: {$blocker['title']} (ID: {$blocker['id']}, Reason: {$blocker['reason']})\n";
            }
        } else {
            echo "Note: No blockers reported, but slot 18:30 was not in available list\n";
        }

        // Test individual space availability to confirm Space 10 IS booked
        $single_result = $this->service->get_slots($space_10_id, $test_date, 60);
        $single_slots = $single_result['slots'] ?? [];

        $slot_18_30_single = null;
        foreach ($single_slots as $slot) {
            if ($slot['start'] === $start_time) {
                $slot_18_30_single = $slot;
                break;
            }
        }

        if ($slot_18_30_single && empty($slot_18_30_single['available'])) {
            echo "✓ CONFIRMED: Space 10 alone has 18:30 blocked (as expected)\n";
        }

        // Cleanup
        $this->cleanup_booking($booking_id);

        echo "\n=== Test Complete ===\n";
    }

    /**
     * Create a test booking
     */
    private function create_booking(
        int $space_id,
        string $date,
        string $start_time,
        string $end_time,
        string $customer_name
    ): ?int {
        $booking_data = [
            'post_type' => 'sb_booking',
            'post_title' => "Test - $customer_name",
            'post_status' => 'publish',
            'meta_input' => [
                '_sb_space_id' => $space_id,
                '_sb_booking_date' => $date,
                '_sb_start_time' => $start_time,
                '_sb_end_time' => $end_time,
                '_sb_customer_name' => $customer_name,
                '_sb_customer_email' => 'test@example.com',
                '_sb_total_price' => '100.00',
                '_sb_status' => 'confirmed',
            ],
        ];

        $booking_id = wp_insert_post($booking_data);

        if (is_wp_error($booking_id)) {
            return null;
        }

        return $booking_id;
    }

    /**
     * Clean up test bookings
     */
    private function cleanup_test_bookings(int $space_id, string $date): void
    {
        global $wpdb;

        // Delete test bookings for this space/date
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->prefix}sb_bookings 
            WHERE space_id = %d 
            AND booking_date = %s 
            AND customer_name LIKE 'Test %%'
        ", $space_id, $date));

        // Also delete any test posts
        $test_posts = get_posts([
            'post_type' => 'sb_booking',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_sb_space_id',
                    'value' => $space_id,
                ],
                [
                    'key' => '_sb_booking_date',
                    'value' => $date,
                ],
                [
                    'key' => '_sb_customer_name',
                    'value' => 'Test%',
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        foreach ($test_posts as $post) {
            wp_delete_post($post->ID, true);
        }
    }

    /**
     * Clean up a single booking
     */
    private function cleanup_booking(int $booking_id): void
    {
        if ($booking_id > 0) {
            wp_delete_post($booking_id, true);
        }
    }
}

// Run the test
$test = new TestCrossSpaceConflict();
$test->run();
