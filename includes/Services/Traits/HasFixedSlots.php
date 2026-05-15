<?php declare(strict_types=1);

namespace SpaceBooking\Services\Traits;

use SpaceBooking\Services\BookingRepository;

/**
 * Trait for handling fixed slots logic.
 * Fixed slots are predefined time slots stored in post meta.
 */
trait HasFixedSlots
{
    abstract protected function getRepository(): BookingRepository;

    /**
     * Check if fixed slots are defined for any of the given space IDs.
     */
    public function has_fixed_slots_defined(array|int $space_ids): bool
    {
        if (!is_array($space_ids)) {
            $space_ids = [$space_ids];
        }

        $primary_id = $space_ids[array_key_first($space_ids)] ?? $space_ids[0] ?? 0;

        if ($primary_id === 0) {
            return false;
        }

        // Check default fixed slots
        $fixed_slots = get_post_meta($primary_id, '_sb_fixed_slots', true);
        if (is_array($fixed_slots) && !empty($fixed_slots)) {
            return true;
        }

        // Check date-specific overrides
        $date_overrides = get_post_meta($primary_id, '_sb_date_overrides', true);
        if (is_array($date_overrides)) {
            foreach ($date_overrides as $override) {
                if (isset($override['status']) && $override['status'] === 'custom' && !empty($override['slots'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get fixed slots for given space IDs on a date.
     */
    public function get_fixed_slots(array|int $space_ids, string $date): array
    {
        if (!is_array($space_ids)) {
            $space_ids = [$space_ids];
        }

        if (count($space_ids) === 1) {
            // Single space - use original logic
            $sid = reset($space_ids);
            return $this->get_fixed_slots_single($sid, $date);
        }

        // Multi-space: Get slots for EACH space, then find intersection
        $per_space_slots = [];
        $repo = $this->getRepository();

        foreach ($space_ids as $sid) {
            $slots = $this->get_fixed_slots_single($sid, $date);
            if (empty($slots)) {
                // This space has no fixed slots defined - fall back to dynamic
                return [];
            }
            $per_space_slots[$sid] = $slots;
        }

        // Find INTERSECTION: slots available in ALL spaces
        $first_id = reset($space_ids);
        $common_slots = [];

        foreach ($per_space_slots[$first_id] as $base_slot) {
            $slot_key = $base_slot['start'] . '-' . $base_slot['end'];
            $is_available_in_all = true;

            // Check this slot is available in ALL other spaces
            foreach ($space_ids as $sid) {
                if ($sid === $first_id) {
                    continue;
                }

                $found = false;
                foreach ($per_space_slots[$sid] as $other_slot) {
                    if ($other_slot['start'] === $base_slot['start'] && $other_slot['end'] === $base_slot['end']) {
                        if (!empty($other_slot['available'])) {
                            $found = true;
                        }
                        break;
                    }
                }
                if (!$found) {
                    $is_available_in_all = false;
                    break;
                }
            }

            if ($is_available_in_all) {
                $common_slots[] = $base_slot;
            }
        }

        error_log('SB_DEBUG: get_fixed_slots intersection: ' . count($common_slots) . ' common slots from ' . count($space_ids) . ' spaces');
        return array_values($common_slots);
    }

    /**
     * Get fixed slots for a SINGLE space (internal helper).
     */
    protected function get_fixed_slots_single(int $space_id, string $date): array
    {
        $repo = $this->getRepository();

        // CONSOLIDATED: get_blocking_intervals already includes confirmed + pending (non-expired)
        $blocked = $repo->get_blocking_intervals([$space_id], $date);
        error_log('SB_DEBUG: get_fixed_slots_single blocking for space ' . $space_id . ': ' . count($blocked));

        $date_overrides = get_post_meta($space_id, '_sb_date_overrides', true);
        if (is_array($date_overrides) && isset($date_overrides[$date])) {
            $override = $date_overrides[$date];
            if ($override['status'] === 'closed') {
                return [];
            }
            if ($override['status'] === 'custom' && !empty($override['slots'])) {
                $fixed_slots = $override['slots'];
            } else {
                return [];
            }
        } else {
            $fixed_slots = get_post_meta($space_id, '_sb_fixed_slots', true);
            if (!is_array($fixed_slots) || empty($fixed_slots)) {
                return [];  // No fixed slots defined
            }
        }

        $space_pre_buf = (int) get_post_meta($space_id, '_sb_buffer_pre_minutes', true) ?: (int) get_option('sb_buffer_pre_minutes', 0);
        $space_post_buf = (int) get_post_meta($space_id, '_sb_buffer_post_minutes', true) ?: (int) get_option('sb_buffer_post_minutes', 0);

        $slots = [];
        foreach ($fixed_slots as $slot_data) {
            $pre_buf = $slot_data['pre_buffer'] ?? $space_pre_buf;
            $post_buf = $slot_data['post_buffer'] ?? $space_post_buf;

            $slot_start = $this->add_minutes($slot_data['start_time'], -$pre_buf);
            $slot_end = $this->add_minutes($slot_data['end_time'], $post_buf);

            // CONSOLIDATED: $blocked already includes pending (non-expired), so just check blocking
            $is_available = !$this->overlaps($slot_start, $slot_end, $blocked);

            $slots[] = [
                'slot_id' => $slot_data['slot_id'],
                'start' => $slot_data['start_time'],
                'end' => $slot_data['end_time'],
                'available' => $is_available,
                'override_price' => $slot_data['override_price'],
                'pre_buffer' => $pre_buf,
                'post_buffer' => $post_buf,
                'capacity' => $slot_data['capacity'] ?? 1
            ];
        }

        return $slots;
    }
}
