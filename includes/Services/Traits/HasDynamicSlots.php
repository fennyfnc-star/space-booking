<?php declare(strict_types=1);

namespace SpaceBooking\Services\Traits;

use SpaceBooking\Services\BookingRepository;

/**
 * Trait for generating dynamic slots from operating hours.
 * Used as fallback when fixed slots are not defined.
 */
trait HasDynamicSlots
{
    abstract protected function getRepository(): BookingRepository;

    /**
     * Generate dynamic slots using global/space hours as fallback when fixed slots empty.
     */
    public function generate_dynamic_slots(int|array $space_ids, string $date, int $step_mins = 60): array
    {
        if (!is_array($space_ids)) {
            $space_ids = [$space_ids];
        }

        if (count($space_ids) === 1) {
            // Single space - use original logic
            $sid = reset($space_ids);
            return $this->generate_dynamic_slots_single($sid, $date, $step_mins);
        }

        // Multi-space: Get slots for EACH space, then find intersection
        $per_space_slots = [];
        foreach ($space_ids as $sid) {
            $slots = $this->generate_dynamic_slots_single($sid, $date, $step_mins);
            if (empty($slots)) {
                // This space has no hours - can't book
                return [];
            }
            $per_space_slots[$sid] = $slots;
        }

        // Find INTERSECTION: slots available in ALL spaces
        $first_id = reset($space_ids);
        $common_slots = [];

        foreach ($per_space_slots[$first_id] as $base_slot) {
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

        error_log('SB_DEBUG: generate_dynamic_slots intersection: ' . count($common_slots) . ' common slots from ' . count($space_ids) . ' spaces');
        return array_values($common_slots);
    }

    /**
     * Generate dynamic slots for a SINGLE space (internal helper).
     * FIXED: Added pending booking detection (like HasFixedSlots).
     */
    protected function generate_dynamic_slots_single(int $space_id, string $date, int $step_mins = 60): array
    {
        $repo = $this->getRepository();

        // CONSOLIDATED: get_blocking_intervals already includes confirmed + pending (non-expired)
        $blocked = $repo->get_blocking_intervals([$space_id], $date);
        error_log('SB_DEBUG: generate_dynamic_slots_single blocking for space ' . $space_id . ': ' . count($blocked));

        [$open, $close] = $this->resolve_effective_hours($space_id, $date);
        error_log("AVAIL DEBUG: dynamic effective open=$open, close=$close");

        if (!$open || !$close) {
            error_log('AVAIL DEBUG: Space closed for dynamic, empty slots');
            return [];
        }

        $slots = $this->generate_slots($open, $close, $step_mins);
        error_log('AVAIL DEBUG: Generated raw dynamic slots count: ' . count($slots));

        [$pre_buf, $post_buf] = $this->resolve_buffers($space_id);
        error_log("AVAIL DEBUG: Dynamic buffers pre=$pre_buf post=$post_buf");

        // Inflate blocking intervals with buffers
        $inflated_intervals = array_map(function ($b) use ($pre_buf, $post_buf) {
            return [
                'start' => $this->add_minutes($b['start'], -$pre_buf),
                'end' => $this->add_minutes($b['end'], $post_buf),
            ];
        }, $blocked);

        // Use a non-static callback to access $this->overlaps()
        $available_count = 0;
        $final_slots = [];
        foreach ($slots as $slot) {
            // Check if slot overlaps with any pending booking first
            $has_pending = $this->overlaps($slot['start'], $slot['end'], $pending_intervals);

            // Slot is available if not blocked by confirmed/in_review/paid AND not pending
            $is_available = !$this->overlaps($slot['start'], $slot['end'], $inflated_intervals) && !$has_pending;

            if ($is_available) {
                $available_count++;
            }
            $slot['available'] = $is_available;
            $slot['has_pending'] = $has_pending;  // FIXED: Now properly detecting pending
            $final_slots[] = $slot;
        }

        error_log("AVAIL DEBUG: Final dynamic available slots: $available_count / " . count($slots));

        return $final_slots;
    }

    /**
     * Resolve effective hours (open/close minus buffers).
     */
    public function resolve_effective_hours(int $space_id, string $date): array
    {
        [$raw_open, $raw_close] = $this->resolve_hours($space_id, $date);
        error_log("AVAIL DEBUG: raw hours open=$raw_open close=$raw_close for space $space_id date $date");

        [$pre_buf, $post_buf] = $this->resolve_buffers($space_id);
        error_log("AVAIL DEBUG: buffers pre=$pre_buf post=$post_buf");

        if (!$raw_open || !$raw_close) {
            error_log('AVAIL DEBUG: Raw hours null, returning null');
            return [null, null];
        }

        $effective_open = $this->add_minutes($raw_open, $pre_buf);
        $effective_close = $this->add_minutes($raw_close, -$post_buf);
        error_log("AVAIL DEBUG: effective open=$effective_open close=$effective_close");

        // Allow if buffers eat entire day (still generate slots in raw window)
        $raw_open_min = $this->time_to_minutes($raw_open);
        $raw_close_min = $this->time_to_minutes($raw_close);
        if ($raw_open_min >= $raw_close_min) {
            error_log('AVAIL DEBUG: Raw duration invalid, returning null');
            return [null, null];
        }
        return [$effective_open, $effective_close];
    }

    /**
     * Resolve hours from space meta or global settings.
     */
    public function resolve_hours(int $space_id, string $date): array
    {
        $day_of_week = (int) (new \DateTime($date))->format('w');  // 0=Sun … 6=Sat
        error_log("AVAIL DEBUG: resolve_hours space_id=$space_id date=$date day=$day_of_week");

        // Check per-space day overrides stored in post meta
        $overrides = get_post_meta($space_id, '_sb_day_overrides', true);
        error_log('AVAIL DEBUG: day_overrides=' . print_r($overrides, true));
        if (is_array($overrides) && isset($overrides[$day_of_week])) {
            $override = $overrides[$day_of_week];
            error_log('AVAIL DEBUG: found override=' . print_r($override, true));
            if (isset($override['closed']) && $override['closed']) {
                error_log('AVAIL DEBUG: Override closed=true');
                return [null, null];
            }
            $open = $override['open'] ?? null;
            $close = $override['close'] ?? null;
            error_log("AVAIL DEBUG: Override hours open=$open close=$close");
            return [$open, $close];
        }

        // Fallback to global defaults
        $global_open = get_option('sb_global_open_time', '09:00');
        $global_close = get_option('sb_global_close_time', '22:00');
        error_log("AVAIL DEBUG: Global fallback open=$global_open close=$global_close");

        return [$global_open, $global_close];
    }

    /**
     * Resolve buffer times for a space.
     */
    public function resolve_buffers(int $space_id): array
    {
        $pre = (int) get_post_meta($space_id, '_sb_buffer_pre_minutes', true);
        $post = (int) get_post_meta($space_id, '_sb_buffer_post_minutes', true);

        if ($pre === 0) {
            $pre = (int) get_option('sb_buffer_pre_minutes', 0);
        }
        if ($post === 0) {
            $post = (int) get_option('sb_buffer_post_minutes', 0);
        }

        return [$pre, $post];
    }
}
