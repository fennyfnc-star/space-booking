<?php
declare(strict_types=1);

namespace SpaceBooking\Services;

/**
 * Global currency utilities for the plugin.
 * Uses WordPress options for currency code; maps to symbols.
 */
final class CurrencyService {

	/**
	 * Common currencies and symbols.
	 */
	public const CURRENCIES = [
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'CAD' => 'C$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'CHF' => 'CHF',
		'JPY' => '¥',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'PLN' => 'zł',
		'CZK' => 'Kč',
		'HUF' => 'Ft',
		'RON' => 'lei',
		'BGN' => 'лв',
		'TRY' => '₺',
		'ILS' => '₪',
		'ZAR' => 'R',
		'MXN' => '$',
		'INR' => '₹',
		'BRL' => 'R$',
		'KRW' => '₩',
	];

	/**
	 * Get current currency code from options (default USD).
	 */
	public static function get_currency(): string {
		return get_option( 'sb_currency', 'USD' );
	}

	/**
	 * Get symbol for current currency.
	 */
	public static function get_symbol(): string {
		$currency = self::get_currency();
		return self::CURRENCIES[ $currency ] ?? '$';
	}

	/**
	 * Format amount: "12.34 €" or locale-aware if needed.
	 */
	public static function format( float $amount, int $decimals = 2 ): string {
		$symbol = self::get_symbol();
		return number_format( $amount, $decimals ) . ' ' . $symbol;
	}

	/**
	 * Get all available currencies for admin select.
	 */
	public static function get_currencies(): array {
		return array_keys( self::CURRENCIES );
	}

	/**
	 * HTML select for currency (for admin forms).
	 */
	public static function render_select( string $name, string $selected = '' ): void {
		if ( ! $selected ) {
			$selected = self::get_currency();
		}
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( self::get_currencies() as $code ): ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected, $code ); ?>>
					<?php echo esc_html( $code . ' (' . self::CURRENCIES[ $code ] . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}

