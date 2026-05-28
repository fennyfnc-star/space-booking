<?php

/**
 * Verification Script for API Data Structure & Multi-Row Insertion Fixes
 *
 * This script verifies:
 * 1. Multi-row insertion creates separate rows for each space ID
 * 2. Availability API returns slots as a proper JSON array [...]
 */
require_once __DIR__ . '/bootstrap.php';

class VerifyFix
{
    private $repo;
    private $test_results = [];

    public function __construct()
    {
        global $wpdb;
        $this->repo = new \SpaceBooking\Services\BookingRepository();
    }

    /**
     * Test 1: Multi-Row Insertion
     * Simulates a booking for two Space IDs and verifies 2 rows exist
     */
    public function test_multi_row_insertion(): bool
    {
        echo "\n=== TEST 1: Multi-Row Insertion ===\n";

        $space_ids = [10, 223];
        $test_date = date('Y-m-d');
        $start_time = '23:00';
        $end_time = '23:59';

        echo 'Testing with space IDs: ' . implode(', ', $space_ids) . "\n";

        try {
            // Create lead booking row (space_ids[0])
            $lead_data = [
                'space_id' => $space_ids[0],
                'booking_date' => $test_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'customer_name' => 'Test Multi-Row Customer',
                'customer_email' => 'test@example.com',
                'customer_phone' => '555-0001',
                'status' => 'pending',
                'expired_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ];

            $booking_id = $this->repo->create($lead_data);
            echo "Created lead booking ID: $booking_id\n";

            // FIXED: Update lead row's order_id (matching BookingController logic)
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'sb_bookings',
                ['order_id' => $booking_id],
                ['id' => $booking_id],
                ['%d'],
                ['%d']
            );
            echo "Updated lead row order_id\n";

            // Create additional row for second space ID (space_ids[1])
            $this->repo->create_booking_row([
                'space_id' => $space_ids[1],
                'order_id' => $booking_id,
                'booking_date' => $test_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'status' => 'pending',
                'expired_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);
            echo "Created additional row for space ID: {$space_ids[1]}\n";

            // CRITICAL: Query by ORDER_ID (user's exact requirement)
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sb_bookings WHERE order_id = %d",
                $booking_id
            ));

            echo "Row count for order_id $booking_id: $count\n";

            $pass = ($count == 2);
            $this->test_results['multi_row_insertion'] = $pass;

            if ($pass) {
                echo "PASS: Expected 2 rows, got $count\n";
            } else {
                echo "FAIL: Expected 2 rows, got $count\n";
            }

            // Cleanup by order_id
            $wpdb->delete($wpdb->prefix . 'sb_bookings', ['order_id' => $booking_id], ['%d']);
            echo "Cleaned up test bookings\n";

            return $pass;
        } catch (\Exception $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";
            $this->test_results['multi_row_insertion'] = false;
            return false;
        }
    }

    /**
     * Test 2: Availability API Returns Array (not Object)
     */
    public function test_availability_returns_array(): bool
    {
        echo "\n=== TEST 2: Availability API Returns Array ===\n";

        $space_id = 224;
        $date = date('Y-m-d');

        echo "Testing availability for space ID: $space_id, date: $date\n";

        try {
            $service = new \SpaceBooking\Services\AvailabilityService($this->repo);
            $result = $service->get_intersection_slots([$space_id], $date);

            $slots = $result['slots'] ?? [];
            echo 'Slots returned: ' . count($slots) . "\n";

            $json = json_encode($slots);
            $first_char = $json[0] ?? '';

            echo "JSON first char: '$first_char'\n";

            $pass = ($first_char === '[');
            $this->test_results['availability_array'] = $pass;

            if ($pass) {
                echo "PASS: slots is a JSON array\n";
            } else {
                echo "FAIL: slots is NOT a JSON array (got '$first_char')\n";
            }

            return $pass;
        } catch (\Exception $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";
            $this->test_results['availability_array'] = false;
            return false;
        }
    }

    /**
     * Test 3: Slots are Iterable
     */
    public function test_slots_are_iterable(): bool
    {
        echo "\n=== TEST 3: Slots are Iterable ===\n";

        $space_id = 224;
        $date = date('Y-m-d');

        try {
            $service = new \SpaceBooking\Services\AvailabilityService($this->repo);
            $result = $service->get_intersection_slots([$space_id], $date);
            $slots = $result['slots'] ?? [];

            $iterable_count = 0;
            foreach ($slots as $slot) {
                $iterable_count++;
            }

            echo "Iterable slots: $iterable_count\n";

            $pass = ($iterable_count > 0);
            $this->test_results['slots_iterable'] = $pass;

            if ($pass) {
                echo "PASS: slots are iterable\n";
            } else {
                echo "FAIL: slots are NOT iterable\n";
            }

            return $pass;
        } catch (\Exception $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";
            $this->test_results['slots_iterable'] = false;
            return false;
        }
    }

    /**
     * Run all tests
     */
    public function run(): bool
    {
        echo "========================================\n";
        echo "  VERIFICATION FIX TESTS\n";
        echo "========================================\n";

        $this->test_multi_row_insertion();
        $this->test_availability_returns_array();
        $this->test_slots_are_iterable();

        echo "\n========================================\n";
        echo "  RESULTS SUMMARY\n";
        echo "========================================\n";

        $passed = 0;
        $total = count($this->test_results);

        foreach ($this->test_results as $test => $result) {
            $status = $result ? 'PASS' : 'FAIL';
            echo "$test: $status\n";
            if ($result)
                $passed++;
        }

        echo "\nTotal: $passed / $total tests passed\n";

        $all_pass = ($passed === $total);
        echo $all_pass ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";

        return $all_pass;
    }
}

$verify = new VerifyFix();
$success = $verify->run();
exit($success ? 0 : 1);
