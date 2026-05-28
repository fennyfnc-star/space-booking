<?php declare(strict_types=1);

namespace SpaceBooking\Services;

/**
 * Export/Import service for CPT data + meta as JSON.
 */
final class ExportImportService
{
	public const CPTS = ['sb_space', 'sb_package', 'sb_extra'];

	/**
	 * Export all CPT posts + meta to JSON string.
	 */
	public function export_json(): string
	{
		$data = [];
		foreach (self::CPTS as $post_type) {
			$posts = get_posts([
				'post_type' => $post_type,
				'post_status' => ['publish', 'draft'],
				'posts_per_page' => -1,
				'orderby' => 'ID',
			]);

			$data[$post_type] = [];
			foreach ($posts as $post) {
				$post_data = [
					'ID' => $post->ID,  // For reference, not used on import
					'post_title' => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_status' => $post->post_status,
					'post_date' => $post->post_date,
					'meta' => [],
				];

				// Get ALL meta keys
				$meta_keys = get_post_meta($post->ID);
				foreach ($meta_keys as $key => $values) {
					$post_data['meta'][$key] = $values[0];  // Single value (arrays stored as JSON)
				}

				$data[$post_type][] = $post_data;
			}
		}

		return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Import from JSON file, optionally delete existing.
	 * @return array [success, message, new_ids map]
	 */
	public function import_json(string $json_file, bool $delete_existing = false): array
	{
		if (!file_exists($json_file)) {
			return [false, 'Temp file missing: ' . basename($json_file)];
		}

		$json = file_get_contents($json_file);
		if (false === $json) {
			return [false, 'Cannot read file contents'];
		}

		$data = json_decode($json, true);
		if (JSON_ERROR_NONE !== json_last_error()) {
			return [false, 'Invalid JSON: ' . json_last_error_msg()];
		}

		if ($delete_existing) {
			foreach (self::CPTS as $post_type) {
				$existing = get_posts(['post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => -1]);
				foreach ($existing as $post) {
					wp_delete_post($post->ID, true);
				}
			}
		}

		$new_ids = [];
		foreach (self::CPTS as $post_type) {
			if (empty($data[$post_type]))
				continue;

			foreach ($data[$post_type] as $item) {
				$postarr = [
					'post_type' => $post_type,
					'post_title' => $item['post_title'],
					'post_content' => $item['post_content'],
					'post_excerpt' => $item['post_excerpt'],
					'post_status' => $item['post_status'],
					'post_date' => $item['post_date'],
				];

				$new_post_id = wp_insert_post($postarr);
				if (is_wp_error($new_post_id))
					continue;

				// Restore meta
				foreach ($item['meta'] ?? [] as $key => $value) {
					// Remap IDs if arrays contain old post IDs (spaces/extras)
					if (in_array($key, ['_sb_package_space_id', '_sb_package_extra_ids', '_sb_allowed_spaces'], true)) {
						$value = $this->remap_ids($value, $new_ids);
					}
					update_post_meta($new_post_id, $key, maybe_unserialize($value));
				}

				$new_ids[$post_type][$item['ID']] = $new_post_id;
			}
		}

		return [true, 'Imported successfully.', $new_ids];
	}

	private function remap_ids($value, array $new_ids_map): mixed
	{
		if (is_array($value)) {
			$mapped = [];
			foreach ($value as $old_id) {
				foreach (self::CPTS as $pt) {
					if (isset($new_ids_map[$pt][$old_id])) {
						$mapped[] = $new_ids_map[$pt][$old_id];
						break;
					}
				}
			}
			return $mapped;
		}
		return $value;
	}
}
