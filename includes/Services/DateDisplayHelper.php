<?php declare(strict_types=1);

namespace SpaceBooking\Services;

final class DateDisplayHelper
{
	public static function format_booking_date(string $raw_date): string
	{
		$raw_date = trim($raw_date);
		if ($raw_date === '') {
			return '';
		}

		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw_date);
		if ($date instanceof \DateTimeImmutable) {
			return $date->format('F j, Y');
		}

		try {
			return (new \DateTimeImmutable($raw_date))->format('F j, Y');
		} catch (\Throwable $e) {
			return $raw_date;
		}
	}
}
