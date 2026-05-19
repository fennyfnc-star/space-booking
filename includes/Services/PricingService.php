<?php declare(strict_types=1);

namespace SpaceBooking\Services;

use DateTime;

/**
 * Calculates booking prices using the priority hierarchy:
 *   1. Package flat price  (overrides everything)
 *   2. Base rate × duration
 *   3. Temporal modifiers (holiday > specific_date > weekend > night)
 *   4. Extras
 */
final class PricingService
{
	/**
	 * @param int|null  $deprecated   Deprecated: was $space_id, now ignored
	 * @param string    $date         Y-m-d
	 * @param string    $start_time   H:i
	 * @param string    $end_time     H:i
	 * @param array     $extras       [ ['extra_id' => int, 'quantity' => int], ... ]
	 * @param array|null $item_ids    NEW: Array of item IDs to price (spaces and packages)
	 * @param array|null $package_ids NEW: Array of package IDs (for extras inclusion calculation)
	 * @param string|null $slot_id
	 * @return array {
	 *   base_price: float,
	 *   modifier_price: float,
	 *   extras_price: float,
	 *   total_price: float,
	 *   breakdown: array,
	 * }
	 */
	public function calculate(
		?int $deprecated = null,
		string $date = '',
		string $start_time = '',
		string $end_time = '',
		array $extras = [],
		?array $item_ids = null,
		?array $package_ids = null,
		?string $slot_id = null
	): array {
		$duration_hours = $this->hours_between($start_time, $end_time);

		$running_total = 0.0;
		$enriched_breakdown = [];
		$item_details = [];  // NEW: per-item tracking
		$total_duration = 0.0;

		// NEW SCHEMA: Use $item_ids array (fallback to first item for backwards compatibility)
		$item_ids = $item_ids ?? ($deprecated ? [$deprecated] : []);
		if (!is_array($item_ids)) {
			error_log('SB_PRICING: Invalid item_ids type, fallback to []');
			$item_ids = [];
		}

		// Validate all item_ids exist and are valid posts
		$valid_ids = [];
		foreach ($item_ids as $id) {
			$post = get_post($id);
			if ($post) {
				if (in_array($post->post_type, ['sb_space', 'sb_package']) && $post->post_status === 'publish') {
					$valid_ids[] = $id;
				}
			}
		}
		// Use validated IDs (empty array if none valid - pricing will return 0)
		$item_ids = $valid_ids;

		foreach ($item_ids as $item_id) {
			$item_type = get_post_type($item_id);
			$item_title = get_the_title($item_id);
			$item_subtotal = 0.0;
			$item_breakdown = [];  // Item-specific breakdown

			if ($item_type === 'sb_package') {
				$package_price = (float) get_post_meta($item_id, '_sb_package_price', true);
				$item_subtotal += $package_price;
				$item_breakdown[] = [
					'label' => $item_title,
					'amount' => $package_price,
				];

				// Add package's included space as Package Inclusion
				$pkg_space_id = get_post_meta($item_id, '_sb_package_space_id', true);
				if ($pkg_space_id) {
					$space_title = get_the_title($pkg_space_id);
					$item_breakdown[] = [
						'label' => $space_title . ' (Package Inclusion)',
						'amount' => 0
					];
				}

				// Add package's included extras as Package Inclusion
				$pkg_extra_ids = get_post_meta($item_id, '_sb_package_extra_ids', true);
				if (is_array($pkg_extra_ids)) {
					foreach ($pkg_extra_ids as $item) {
						$extra_id = is_array($item) ? (int) ($item['extra_id'] ?? $item['id'] ?? 0) : (int) $item;
						if ($extra_id > 0) {
							$extra_title = get_the_title($extra_id);
							$item_breakdown[] = [
								'label' => $extra_title . ' (Package Inclusion)',
								'amount' => 0
							];
						}
					}
				}
			} else if ($item_type === 'sb_space') {
				$item_duration = $duration_hours;
				$total_duration += $item_duration;
				$fixed_key = '_sb_fixed_price_' . round($item_duration) . 'hours';
				$fixed_price = (float) get_post_meta($item_id, $fixed_key, true);
				$segments = $this->get_price_segments($item_id, $date, $start_time, $end_time);
				if (!empty($segments)) {
					$base_price = 0.0;
					foreach ($segments as $seg) {
						$seg_hours = ($seg['end_min'] - $seg['start_min']) / 60.0;
						$seg_price = round($seg['rate'] * $seg_hours, 2);
						$base_price += $seg_price;
						$item_breakdown[] = [
							'label' => sprintf('%s (%s)', $item_title, $seg['start_time'] . '–' . $seg['end_time']),
							'amount' => $seg_price,
						];
					}
					$item_subtotal += $base_price;
				} else {
					$base_rate = $fixed_price ?: (float) get_post_meta($item_id, '_sb_hourly_rate', true);
					$item_base = $base_rate * $item_duration;
					$item_subtotal += $item_base;
					$item_breakdown[] = [
						'label' => sprintf('%s (%.1fh)', $item_title, $item_duration),
						'amount' => $item_base,
					];
				}

				// Slot override for this item
				if ($slot_id) {
					$fixed_slots = get_post_meta($item_id, '_sb_fixed_slots', true);
					if (is_array($fixed_slots)) {
						foreach ($fixed_slots as $slot) {
							if ($slot['slot_id'] === $slot_id) {
								$slot_price = $slot['override_price'] ?? null;
								if ($slot_price !== null) {
									$item_subtotal = $slot_price;
									$item_breakdown = [['label' => $item_title . ' Fixed Slot', 'amount' => $slot_price]];
									break;
								}
							}
						}
					}
				}
			}

			// Stackable modifiers per item (remove priority skip)
			[$modifier_price, $mod_breakdown] = $this->apply_modifiers($item_id, $date, $start_time, $end_time, $item_subtotal);
			$item_subtotal += $modifier_price;
			$item_breakdown = array_merge($item_breakdown, $mod_breakdown);  // Include in item breakdown

			// Get featured image URL for WooCommerce product image
			$thumbnail = '';
			if (has_post_thumbnail($item_id)) {
				$thumb_id = get_post_thumbnail_id($item_id);
				$thumb_url = wp_get_attachment_image_url($thumb_id, 'medium');
				if ($thumb_url) {
					$thumbnail = $thumb_url;
				}
			}

			// Store per-item detail
			$item_details[] = [
				'id' => $item_id,
				'type' => $item_type,
				'title' => $item_title,
				'subtotal' => round($item_subtotal, 2),
				'breakdown' => $item_breakdown,
				'thumbnail' => $thumbnail
			];

			$running_total += $item_subtotal;
			$enriched_breakdown = array_merge($enriched_breakdown, $item_breakdown);
		}

		$display_duration = $duration_hours;
		// NEW SCHEMA: Use package_ids array for extras allowance calculation
		$extras_result = $this->calculate_extras_with_allowance($extras, $package_ids);
		$extras_price = $extras_result['total'];
		$total = $running_total + $extras_price;

		$breakdown = $enriched_breakdown;
		$extras_breakdown = [];
		if ($extras_price > 0) {
			$extras_breakdown = $extras_result['breakdown'];
			$breakdown = array_merge($breakdown, $extras_breakdown);
		}

		return [
			'base_price' => $running_total,
			'extras_price' => $extras_price,
			'total_price' => round($total, 2),
			'duration_hours' => $duration_hours,
			'display_duration' => round($display_duration, 1),
			'breakdown' => $breakdown,
			'items' => $item_details,
			'extras_breakdown' => $extras_breakdown,
			'extras_details' => $extras_result['details']  // NEW: UI details with included/paid split
		];
	}

	private function get_extras_breakdown(array $extras): array
	{
		$breakdown = [];
		$group_names = [];
		foreach ($extras as $extra) {
			$extra_id = (int) $extra['extra_id'];
			$qty = max(1, (int) ($extra['quantity'] ?? 1));
			$price = (float) get_post_meta($extra_id, '_sb_extra_price', true);
			$title = get_the_title($extra_id);
			$total = $price * $qty;
			$breakdown[] = [
				'label' => $title . ($qty > 1 ? ' (x' . $qty . ')' : ''),
				'amount' => $total
			];
			$group_names[] = $title . ($qty > 1 ? ' x' . $qty : '');
		}
		return $breakdown;
	}

	// ── Temporal modifiers ───────────────────────────────────────────────────

	/**
	 * Returns [ total_modifier_amount, breakdown_array ]
	 */
	private function apply_modifiers(
		int $space_id,
		string $date,
		string $start_time,
		string $end_time,
		float $base_price
	): array {
		global $wpdb;

		// Fetch applicable rules ordered by priority DESC (higher = applied first)
		$rules = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sb_pricing_rules
		\t WHERE is_active = 1
		\t   AND (space_id IS NULL OR space_id = %d)
		\t ORDER BY priority DESC",
			$space_id
		), ARRAY_A);

		$dt = new DateTime($date);
		$day_of_week = (int) $dt->format('w');  // 0=Sun … 6=Sat
		$date_str = $dt->format('Y-m-d');

		// Priority rank: holiday=40, date_specific=30, weekend=20, night/day=10
		$priority_map = [
			'holiday' => 40,
			'date_specific' => 30,
			'date_range' => 25,
			'weekend' => 20,
			'weekday' => 15,
			'night' => 10,
			'day' => 10,
		];

		$applied = [];
		$applied_rank = 0;
		$modifier_sum = 0.0;
		$breakdown = [];

		foreach ($rules as $rule) {
			$rank = $priority_map[$rule['rule_type']] ?? 0;

			// Don't apply lower-priority rules if we already have a higher one
			if ($rank < $applied_rank && !empty($applied)) {
				continue;
			}

			if (!$this->rule_matches($rule, $date_str, $day_of_week, $start_time, $end_time)) {
				continue;
			}

			$amount = $rule['modifier'] === 'percent'
				? round($base_price * ((float) $rule['value'] / 100), 2)
				: (float) $rule['value'];

			$modifier_sum += $amount;
			$applied_rank = $rank;
			$symbol = \SpaceBooking\Services\CurrencyService::get_symbol();
			$breakdown[] = [
				'label' => $rule['label'] ?? ucfirst(str_replace('_', ' ', $rule['rule_type'])),
				'amount' => $amount,
			];

			$applied[] = $rule['id'];
		}

		return [$modifier_sum, $breakdown];
	}

	private function rule_matches(
		array $rule,
		string $date,
		int $day_of_week,
		string $start_time,
		string $end_time
	): bool {
		switch ($rule['rule_type']) {
			case 'holiday':
			case 'date_specific':
				return $rule['start_date'] === $date;

			case 'date_range':
				return ($rule['start_date'] <= $date && $date <= $rule['end_date']);

			case 'weekend':
				return in_array($day_of_week, [0, 6], true);  // Sun or Sat

			case 'weekday':
				return !in_array($day_of_week, [0, 6], true);

			case 'night':
				// Night: booking start overlaps the rule's night window
				return ($rule['start_time'] && $start_time >= $rule['start_time']) ||
					($rule['end_time'] && $end_time <= $rule['end_time']);

			case 'day':
				return (!$rule['start_time'] || $start_time >= $rule['start_time']) &&
					(!$rule['end_time'] || $end_time <= $rule['end_time']);

			default:
				// days_of_week CSV check
				if ($rule['days_of_week']) {
					$days = array_map('intval', explode(',', $rule['days_of_week']));
					return in_array($day_of_week, $days, true);
				}
		}
		return false;
	}

	// ── Extras ───────────────────────────────────────────────────────────────

	/**
	 * Calculate extras price with package allowance (first unit free logic)
	 * 
	 * @param array      $extras        [ ['extra_id' => int, 'quantity' => int], ... ]
	 * @param array|null $package_ids   NEW: Array of package IDs to check for included extras
	 * @return array {
	 *   total: float,
	 *   breakdown: array,
	 *   details: array  // For UI: extra_id, title, total_qty, included_qty, paid_qty, unit_price
	 * }
	 */
	private function calculate_extras_with_allowance(array $extras, ?array $package_ids = null): array
	{
		$total = 0.0;
		$breakdown = [];
		$details = [];
		
		// Get included extras from all packages (NEW: iterate over array)
		$included_extras = [];
		$package_ids = $package_ids ?? [];
		foreach ($package_ids as $pkg_id) {
			$pkg_extra_ids = get_post_meta($pkg_id, '_sb_package_extra_ids', true);
			if (is_array($pkg_extra_ids)) {
				foreach ($pkg_extra_ids as $item) {
					// Support both flat array [5, 10] and object array [{extra_id: 5, quantity: 1}, ...]
					if (is_array($item)) {
						$extra_id = (int) ($item['extra_id'] ?? $item['id'] ?? 0);
						$qty = (int) ($item['quantity'] ?? 1);
					} else {
						// Flat array format: just the ID
						$extra_id = (int) $item;
						$qty = 1;
					}
					if ($extra_id > 0) {
						// Use max to handle multiple packages (take highest included qty)
						$current = $included_extras[$extra_id] ?? 0;
						$included_extras[$extra_id] = max($current, $qty);
					}
				}
			}
		}
		
		foreach ($extras as $item) {
			$extra_id = (int) $item['extra_id'];
			$requested_qty = max(1, (int) ($item['quantity'] ?? 1));
			$unit_price = (float) get_post_meta($extra_id, '_sb_extra_price', true);
			$title = get_the_title($extra_id);
			
			// Get included quantity for this extra (0 if no package)
			$included_qty = $included_extras[$extra_id] ?? 0;
			
			// Calculate chargeable quantity: requested - included (cannot be negative)
			$paid_qty = max(0, $requested_qty - $included_qty);
			$paid_amount = $unit_price * $paid_qty;
			
			$total += $paid_amount;
			
			// Build breakdown entry - show both included and paid
			if ($included_qty > 0 && $paid_qty > 0) {
				// Partially included: show both lines
				$breakdown[] = [
					'label' => $title . ' (Package Inclusion)',
					'amount' => 0
				];
				$breakdown[] = [
					'label' => $title . ' (x' . $paid_qty . ')',
					'amount' => $paid_amount
				];
			} elseif ($included_qty > 0) {
				// Fully included
				$breakdown[] = [
					'label' => $title . ' (Package Inclusion)',
					'amount' => 0
				];
			} elseif ($paid_qty > 0) {
				// Not included, pay for all
				$breakdown[] = [
					'label' => $title . ($paid_qty > 1 ? ' (x' . $paid_qty . ')' : ''),
					'amount' => $paid_amount
				];
			}
			
			// Build detail entry for UI
			$details[] = [
				'extra_id' => $extra_id,
				'title' => $title,
				'total_qty' => $requested_qty,
				'included_qty' => $included_qty,
				'paid_qty' => $paid_qty,
				'unit_price' => $unit_price,
				'is_locked' => ($requested_qty <= $included_qty)
			];
		}
		
		// Note: Package-included extras are already added in item_breakdown when processing package item
		// No need to add them again here
		
		return [
			'total' => round($total, 2),
			'breakdown' => $breakdown,
			'details' => $details
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function get_price_segments(int $space_id, string $date, string $start_time, string $end_time): array
	{
		$base_rate = (float) get_post_meta($space_id, '_sb_hourly_rate', true);
		$overrides = get_post_meta($space_id, '_sb_price_overrides', true) ?: [];

		$booking_start_min = $this->time_to_minutes($start_time);
		$booking_end_min = $this->time_to_minutes($end_time);

		$segments = [[
			'start_min' => $booking_start_min,
			'end_min' => $booking_end_min,
			'start_time' => $start_time,
			'end_time' => $end_time,
			'rate' => $base_rate
		]];

		foreach ($overrides as $ov) {
			$ov_days = $ov['days'] ?? [];
			$date_wday = (new DateTime($date))->format('w');  // 0=Sun..6=Sat
			if (!in_array((int) $date_wday, $ov_days))
				continue;

			$ov_start_min = $this->time_to_minutes($ov['start_time']);
			$ov_end_min = $this->time_to_minutes($ov['end_time']);

			$new_segments = [];
			foreach ($segments as $seg) {
				if ($seg['end_min'] <= $ov_start_min || $seg['start_min'] >= $ov_end_min) {
					// No overlap
					$new_segments[] = $seg;
					continue;
				}

				// Pre-overlap
				if ($seg['start_min'] < $ov_start_min) {
					$new_segments[] = [
						'start_min' => $seg['start_min'],
						'end_min' => $ov_start_min,
						'start_time' => $seg['start_time'],
						'end_time' => $this->minutes_to_time($ov_start_min),
						'rate' => $seg['rate']
					];
				}

				// Overlap
				$overlap_start = max($seg['start_min'], $ov_start_min);
				$overlap_end = min($seg['end_min'], $ov_end_min);
				$new_segments[] = [
					'start_min' => $overlap_start,
					'end_min' => $overlap_end,
					'start_time' => $this->minutes_to_time($overlap_start),
					'end_time' => $this->minutes_to_time($overlap_end),
					'rate' => (float) $ov['hourly_rate']
				];

				// Post-overlap
				if ($seg['end_min'] > $ov_end_min) {
					$new_segments[] = [
						'start_min' => $ov_end_min,
						'end_min' => $seg['end_min'],
						'start_time' => $this->minutes_to_time($ov_end_min),
						'end_time' => $seg['end_time'],
						'rate' => $seg['rate']
					];
				}
			}
			$segments = $new_segments;
		}

		// Merge adjacent same-rate segments
		return $this->merge_segments($segments);
	}

	private function time_to_minutes(string $time): int
	{
		[$h, $m] = explode(':', $time);
		return (int) $h * 60 + (int) $m;
	}

	private function minutes_to_time(int $minutes): string
	{
		$h = floor($minutes / 60);
		$m = $minutes % 60;
		return sprintf('%02d:%02d', $h, $m);
	}

	private function merge_segments(array $segments): array
	{
		if (empty($segments))
			return [];

		usort($segments, fn($a, $b) => $a['start_min'] <=> $b['start_min']);

		$merged = [$segments[0]];
		foreach ($segments as $curr) {
			$last = &$merged[count($merged) - 1];

			// Merge if overlapping/adjacent OR duplicate AND same rate
			if ($last['rate'] === $curr['rate'] &&
					($last['end_min'] >= $curr['start_min'])) {
				$last['end_min'] = max($last['end_min'], $curr['end_min']);
				$last['end_time'] = $this->minutes_to_time($last['end_min']);
			} else {
				$merged[] = $curr;
			}
		}
		return $merged;
	}

	private function hours_between(string $start, string $end): float
	{
		$s = new DateTime("1970-01-01 {$start}");
		$e = new DateTime("1970-01-01 {$end}");
		return round(($e->getTimestamp() - $s->getTimestamp()) / 3600, 2);
	}
}
