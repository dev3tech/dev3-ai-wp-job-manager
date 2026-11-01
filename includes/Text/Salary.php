<?php
/**
 * Salary normaliser: free-text WPJM salaries ("$50k-70k/yr", "£30,000 - £35,000 per annum",
 * "20-25 USD/hour", "INR 12 LPA") → { min, max, currency, unit }. No AI needed; the AI normaliser
 * only runs when these rules give up.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Text;

use Dev3\AiWpJobManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Salary {

	const UNITS = array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' );

	/**
	 * ISO codes we recognise as plain words.
	 *
	 * @var string[]
	 */
	const CODES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'INR', 'PKR', 'BDT', 'LKR', 'JPY', 'CNY', 'HKD', 'SGD', 'MYR', 'IDR', 'PHP', 'THB', 'VND', 'KRW', 'AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR', 'ILS', 'TRY', 'ZAR', 'NGN', 'KES', 'EGP', 'MAD', 'BRL', 'MXN', 'ARS', 'CLP', 'COP', 'PEN', 'UAH' );

	/**
	 * Currency symbols/words → ISO (longest first matters).
	 *
	 * @return array
	 */
	private static function symbols() {
		return array(
			'US$'     => 'USD',
			'C$'      => 'CAD',
			'CA$'     => 'CAD',
			'A$'      => 'AUD',
			'AU$'     => 'AUD',
			'NZ$'     => 'NZD',
			'R$'      => 'BRL',
			'S$'      => 'SGD',
			'HK$'     => 'HKD',
			'£'       => 'GBP',
			'€'       => 'EUR',
			'₹'       => 'INR',
			'¥'       => 'JPY',
			'₩'       => 'KRW',
			'₱'       => 'PHP',
			'₦'       => 'NGN',
			'₺'       => 'TRY',
			'zł'      => 'PLN',
			'rs.'     => 'INR',
			'rs'      => 'INR',
			'dollars' => '$',
			'dollar'  => '$',
			'euros'   => 'EUR',
			'euro'    => 'EUR',
			'pounds'  => 'GBP',
			'rupees'  => 'INR',
			'lpa'     => 'INR',
			'lakh'    => 'INR',
			'lakhs'   => 'INR',
			'$'       => '$',
		);
	}

	/**
	 * Unit patterns (checked in order).
	 *
	 * @return array regex => unit
	 */
	private static function unit_patterns() {
		return array(
			'/(\bper\s+hour\b|\bhourly\b|\/\s*h(ou)?r\b|\ban?\s+hour\b|\bp\.?\/?h\b|\bph\b)/i' => 'HOUR',
			'/(\bper\s+day\b|\bdaily\b|\/\s*day\b|\ba\s+day\b|\bday\s+rate\b|\bp\.?d\.?\b)/i' => 'DAY',
			'/(\bper\s+week\b|\bweekly\b|\/\s*w(ee)?k\b|\ba\s+week\b|\bp\.?w\.?\b)/i' => 'WEEK',
			'/(\bper\s+month\b|\bmonthly\b|\/\s*mo(nth)?\b|\ba\s+month\b|\bp\.?m\.?\b|\bpcm\b)/i' => 'MONTH',
			'/(\bper\s+(year|annum)\b|\bannual(ly)?\b|\byearly\b|\/\s*y(ea)?r\b|\ba\s+year\b|\bp\.?a\.?\b|\blpa\b|\bper\s+an+um\b|\bctc\b)/i' => 'YEAR',
		);
	}

	/**
	 * Parses a salary string.
	 *
	 * @param string $raw      Raw salary text.
	 * @param string $currency Known currency (WPJM field) or ''.
	 * @param string $unit     Known unit (WPJM field) or ''.
	 * @return array { ok, min, max, currency, unit, label, negotiable, guessed_unit, source, raw }
	 */
	public static function parse( $raw, $currency = '', $unit = '' ) {
		$raw  = trim( wp_strip_all_tags( (string) $raw ) );
		$text = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
		$out  = array(
			'ok'           => false,
			'min'          => null,
			'max'          => null,
			'currency'     => self::clean_currency( $currency ),
			'unit'         => in_array( strtoupper( (string) $unit ), self::UNITS, true ) ? strtoupper( (string) $unit ) : '',
			'label'        => '',
			'negotiable'   => false,
			'guessed_unit' => false,
			'source'       => 'rules',
			'raw'          => $raw,
		);
		if ( '' === $text ) {
			return $out;
		}
		if ( preg_match( '/\b(competitive|negotiable|doe|d\.o\.e|depending on experience|tbd|tba|attractive|market rate|n\/a)\b/i', $text ) && ! preg_match( '/\d/', $text ) ) {
			$out['negotiable'] = true;
			return $out;
		}

		// Currency.
		if ( '' === $out['currency'] ) {
			$out['currency'] = self::detect_currency( $text );
		}

		// Unit.
		if ( '' === $out['unit'] ) {
			foreach ( self::unit_patterns() as $pattern => $u ) {
				if ( preg_match( $pattern, $text ) ) {
					$out['unit'] = $u;
					break;
				}
			}
		}

		// Numbers with suffixes: 50k, 4.5k, 1.2m, 12 lpa / 12 lakh.
		$lakh  = (bool) preg_match( '/\b(lpa|lakhs?|lacs?)\b/i', $text );
		$crore = (bool) preg_match( '/\b(crores?|cr)\b/i', $text );
		preg_match_all( '/(\d{1,3}(?:[,.\s]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d+)?)\s*(k|m|mn|thousand|million)?\b/i', $text, $m, PREG_SET_ORDER );
		$nums = array();
		foreach ( $m as $hit ) {
			$value = self::to_number( $hit[1] );
			if ( null === $value ) {
				continue;
			}
			$suffix = isset( $hit[2] ) ? strtolower( $hit[2] ) : '';
			$mult   = 1;
			if ( 'k' === $suffix || 'thousand' === $suffix ) {
				$mult = 1000;
			} elseif ( in_array( $suffix, array( 'm', 'mn', 'million' ), true ) ) {
				$mult = 1000000;
			} elseif ( $lakh && $value < 1000 ) {
				$mult = 100000;
			} elseif ( $crore && $value < 1000 ) {
				$mult = 10000000;
			}
			$nums[] = array(
				'value'  => $value,
				'mult'   => $mult,
				'suffix' => '' !== $suffix,
			);
		}
		if ( ! $nums ) {
			$out['negotiable'] = (bool) preg_match( '/\b(competitive|negotiable|doe|depending)\b/i', $text );
			return $out;
		}
		$nums = array_slice( $nums, 0, 2 );
		// "50-70k": the suffix of the second number applies to a bare, smaller first number.
		if ( 2 === count( $nums ) && ! $nums[0]['suffix'] && $nums[1]['suffix'] && 1 === $nums[0]['mult'] && $nums[0]['value'] < 1000 ) {
			$nums[0]['mult'] = $nums[1]['mult'];
		}
		$values = array();
		foreach ( $nums as $n ) {
			$values[] = round( $n['value'] * $n['mult'], 2 );
		}
		$is_max_only = (bool) preg_match( '/\b(up\s*to|max(imum)?|not\s+more\s+than|under)\b/i', $text );
		$is_min_only = (bool) preg_match( '/(\bfrom\b|\bmin(imum)?\b|\bstarting\s+(at|from)\b|\bat\s+least\b|\d\s*k?\s*\+)/i', $text );
		if ( 1 === count( $values ) ) {
			if ( $is_max_only ) {
				$out['max'] = $values[0];
			} elseif ( $is_min_only ) {
				$out['min'] = $values[0];
			} else {
				$out['min'] = $values[0];
				$out['max'] = $values[0];
			}
		} else {
			$out['min'] = min( $values );
			$out['max'] = max( $values );
		}
		$top = null !== $out['max'] ? $out['max'] : $out['min'];
		if ( $top <= 0 ) {
			return $out;
		}
		if ( '' === $out['unit'] ) {
			$out['guessed_unit'] = true;
			$out['unit']         = $top >= 15000 ? 'YEAR' : ( $top >= 1000 ? 'MONTH' : 'HOUR' );
		}
		if ( '' === $out['currency'] || '$' === $out['currency'] ) {
			$site            = Settings::currency();
			$out['currency'] = '$' === $out['currency'] && ! in_array( $site, array( 'USD', 'CAD', 'AUD', 'NZD', 'SGD', 'HKD', 'MXN' ), true ) ? 'USD' : $site;
		}
		$out['ok']    = true;
		$out['label'] = self::label( $out['min'], $out['max'] );
		return $out;
	}

	/**
	 * Number string → float, handling 50,000 / 50.000 / 4,5 / 4.5.
	 *
	 * @param string $s Number text.
	 * @return float|null
	 */
	public static function to_number( $s ) {
		$s = preg_replace( '/\s+/', '', (string) $s );
		if ( '' === $s ) {
			return null;
		}
		if ( preg_match( '/^\d{1,3}([,.])\d{3}(\1\d{3})*([.,]\d{1,2})?$/', $s, $m ) ) {
			$thousands = $m[1];
			$decimal   = ',' === $thousands ? '.' : ',';
			$s         = str_replace( $thousands, '', $s );
			$s         = str_replace( $decimal, '.', $s );
			return (float) $s;
		}
		return (float) str_replace( ',', '.', $s );
	}

	/**
	 * Currency from the text ('' when none; '$' when only a bare dollar sign).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function detect_currency( $text ) {
		if ( preg_match( '/\b(' . implode( '|', self::CODES ) . ')\b/', strtoupper( $text ), $m ) ) {
			return $m[1];
		}
		$lower = mb_strtolower( $text );
		foreach ( self::symbols() as $sym => $code ) {
			$is_word = (bool) preg_match( '/^[a-z.]+$/', $sym );
			if ( $is_word ? preg_match( '/\b' . preg_quote( $sym, '/' ) . '(?![a-z])/', $lower ) : false !== mb_strpos( $lower, mb_strtolower( $sym ) ) ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * ISO currency or ''.
	 *
	 * @param string $c Raw.
	 * @return string
	 */
	public static function clean_currency( $c ) {
		$c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
		return 3 === strlen( $c ) ? $c : '';
	}

	/**
	 * Number label for WPJM's salary field ("50,000 – 70,000"); currency and unit have their own fields.
	 *
	 * @param float|null $min Min.
	 * @param float|null $max Max.
	 * @return string
	 */
	public static function label( $min, $max ) {
		$fmt = static function ( $v ) {
			$dec = floor( $v ) === (float) $v ? 0 : 2;
			return number_format( (float) $v, $dec, '.', ',' );
		};
		if ( null !== $min && null !== $max ) {
			return $min === $max ? $fmt( $min ) : $fmt( $min ) . ' – ' . $fmt( $max );
		}
		if ( null !== $max ) {
			/* translators: %s: amount */
			return sprintf( __( 'Up to %s', 'dev3-ai-wp-job-manager' ), $fmt( $max ) );
		}
		if ( null !== $min ) {
			/* translators: %s: amount */
			return sprintf( __( 'From %s', 'dev3-ai-wp-job-manager' ), $fmt( $min ) );
		}
		return '';
	}

	/**
	 * Human reading ("USD 50,000 – 70,000 per year").
	 *
	 * @param array $s Parsed salary.
	 * @return string
	 */
	public static function describe( array $s ) {
		if ( empty( $s['ok'] ) ) {
			return '';
		}
		$units = array(
			'HOUR'  => __( 'per hour', 'dev3-ai-wp-job-manager' ),
			'DAY'   => __( 'per day', 'dev3-ai-wp-job-manager' ),
			'WEEK'  => __( 'per week', 'dev3-ai-wp-job-manager' ),
			'MONTH' => __( 'per month', 'dev3-ai-wp-job-manager' ),
			'YEAR'  => __( 'per year', 'dev3-ai-wp-job-manager' ),
		);
		return trim( $s['currency'] . ' ' . self::label( $s['min'], $s['max'] ) . ' ' . ( isset( $units[ $s['unit'] ] ) ? $units[ $s['unit'] ] : '' ) );
	}

	/**
	 * Cleans an accepted salary draft.
	 *
	 * @param array $in Draft { min, max, currency, unit }.
	 * @return array|null
	 */
	public static function clean( array $in ) {
		$num = static function ( $v ) {
			return ( null === $v || '' === $v ) ? null : round( max( 0, (float) $v ), 2 );
		};
		$min = $num( isset( $in['min'] ) ? $in['min'] : null );
		$max = $num( isset( $in['max'] ) ? $in['max'] : null );
		if ( null === $min && null === $max ) {
			return null;
		}
		if ( null !== $min && null !== $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}
		$unit = isset( $in['unit'] ) ? strtoupper( (string) $in['unit'] ) : 'YEAR';
		$cur  = self::clean_currency( isset( $in['currency'] ) ? $in['currency'] : '' );
		return array(
			'ok'       => true,
			'min'      => $min,
			'max'      => $max,
			'currency' => '' === $cur ? Settings::currency() : $cur,
			'unit'     => in_array( $unit, self::UNITS, true ) ? $unit : 'YEAR',
			'label'    => self::label( $min, $max ),
		);
	}

	/**
	 * Writes an accepted salary into WPJM's fields plus our numeric meta.
	 *
	 * @param int   $id     Listing.
	 * @param array $salary Draft.
	 * @return bool
	 */
	public static function write( $id, array $salary ) {
		$s = self::clean( $salary );
		if ( ! $s ) {
			return false;
		}
		update_post_meta( $id, '_job_salary', $s['label'] );
		update_post_meta( $id, '_job_salary_currency', $s['currency'] );
		update_post_meta( $id, '_job_salary_unit', $s['unit'] );
		self::store_numbers( $id, $s );
		return true;
	}

	/**
	 * Stores numeric min/max for schema (does not touch WPJM's fields).
	 *
	 * @param int   $id Listing.
	 * @param array $s  Parsed salary.
	 */
	public static function store_numbers( $id, array $s ) {
		foreach ( array( 'min', 'max' ) as $k ) {
			if ( null === $s[ $k ] ) {
				delete_post_meta( $id, '_dev3jm_salary_' . $k );
			} else {
				update_post_meta( $id, '_dev3jm_salary_' . $k, $s[ $k ] );
			}
		}
	}

	/**
	 * Re-parses a listing's current WPJM salary and stores the numbers (save hooks).
	 *
	 * @param int $id Listing.
	 * @return array Parsed.
	 */
	public static function sync( $id ) {
		$p = self::parse( (string) get_post_meta( $id, '_job_salary', true ), (string) get_post_meta( $id, '_job_salary_currency', true ), (string) get_post_meta( $id, '_job_salary_unit', true ) );
		if ( $p['ok'] ) {
			self::store_numbers( $id, $p );
		} else {
			delete_post_meta( $id, '_dev3jm_salary_min' );
			delete_post_meta( $id, '_dev3jm_salary_max' );
		}
		return $p;
	}
}
