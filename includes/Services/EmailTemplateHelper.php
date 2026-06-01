<?php declare(strict_types=1);

namespace SpaceBooking\Services;

final class EmailTemplateHelper
{
	public const PRIMARY_COLOR = '#7A48B0';

	/**
	 * @return array<int, array{label:string,value:string,others_text:string}>
	 */
	public static function package_question_rows_from_meta_string(string $raw): array
	{
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$rows = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$label = sanitize_text_field((string) ($entry['field_label'] ?? ''));
			$value = $entry['value'] ?? '';
			$others = sanitize_textarea_field((string) ($entry['others_text'] ?? ''));

			if (is_array($value)) {
				$value_text = implode(', ', array_map(static fn($v): string => sanitize_text_field((string) $v), $value));
			} else {
				$value_text = sanitize_text_field((string) $value);
			}

			if ($label === '' || $value_text === '') {
				continue;
			}

			$rows[] = [
				'label' => $label,
				'value' => $value_text,
				'others_text' => $others,
			];
		}

		return $rows;
	}

	/**
	 * @param array<int, array{label:string,value:string,others_text:string}> $rows
	 */
	public static function render_package_qa_html(array $rows): string
	{
		if (empty($rows)) {
			return '';
		}

		$html = '<h3 style="font-size:15px;margin:16px 0 10px;color:#777;">' . esc_html__('Package Answers', 'space-booking') . '</h3>';
		$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
		foreach ($rows as $row) {
			$html .= '<tr>';
			$html .= '<td style="padding:8px 0;width:38%;color:#555;font-size:13px;"><strong>' . esc_html($row['label']) . '</strong></td>';
			$html .= '<td style="padding:8px 0;font-size:13px;">' . esc_html($row['value']);
			if ($row['others_text'] !== '') {
				$html .= '<br><span style="color:#666;font-size:12px;"><em>' . esc_html__('Others explanation:', 'space-booking') . '</em> ' . esc_html($row['others_text']) . '</span>';
			}
			$html .= '</td>';
			$html .= '</tr>';
		}
		$html .= '</table>';

		return $html;
	}
}

