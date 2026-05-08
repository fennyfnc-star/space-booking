<?php declare(strict_types=1);

namespace SpaceBooking\Services\Traits;

use DateInterval;
use DateTime;

/**
 * Trait for generating time slots from open/close hours.
 */
trait HasSlotGeneration
{
    /**
     * Generate slots from open/close times using step interval.
     */
    public function generate_slots(string $open, string $close, int $step_mins): array
    {
        $slots = [];
        $cursor = new DateTime("1970-01-01 {$open}");
        $end = new DateTime("1970-01-01 {$close}");
        $step = new DateInterval('PT' . $step_mins . 'M');

        while (true) {
            $slot_end = (clone $cursor)->add($step);
            if ($slot_end > $end) {
                break;
            }
            $slots[] = [
                'start' => $cursor->format('H:i'),
                'end' => $slot_end->format('H:i'),
            ];
            $cursor->add($step);
        }

        return $slots;
    }

    /**
     * Add minutes to a time string.
     */
    public function add_minutes(string $time_str, int $minutes): string
    {
        $dt = new DateTime("1970-01-01 {$time_str}");
        $interval_str = 'PT' . abs($minutes) . 'M';
        if ($minutes < 0) {
            $dt->sub(new DateInterval($interval_str));
        } else {
            $dt->add(new DateInterval($interval_str));
        }
        $hours = (int) $dt->format('H');
        $mins = (int) $dt->format('i');
        if ($hours < 0) {
            $hours = 0;
        }
        if ($hours > 23) {
            $hours = 23;
        }
        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * Convert time string to minutes since midnight.
     */
    public function time_to_minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return (int) $h * 60 + (int) $m;
    }

    /**
     * Convert minutes since midnight to time string.
     */
    public function minutes_to_time(int $minutes): string
    {
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }
}
