<?php declare(strict_types=1);

namespace SpaceBooking\Services\Traits;

/**
 * Trait for detecting overlapping time intervals.
 * Used for booking collision detection.
 */
trait HasOverlapDetection
{
    /**
     * Returns true if [slotStart, slotEnd) overlaps any of the booked intervals.
     */
    public function overlaps(string $slot_start, string $slot_end, array $booked): bool
    {
        foreach ($booked as $b) {
            // Overlap condition: slot_start < b_end AND slot_end > b_start
            if ($slot_start < $b['end'] && $slot_end > $b['start']) {
                return true;
            }
        }
        return false;
    }
}
