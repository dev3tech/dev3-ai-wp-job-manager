<?php
/**
 * Job title normaliser (rules): expands abbreviations, removes hype words, emojis, shouting and
 * stuffed salary/location bits, title-cases and extracts the seniority level.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Title {

	const LEVELS = array( 'entry', 'mid', 'senior', 'lead', 'executive' );

	/**
	 * Abbreviations (whole words, case-insensitive).
	 *
	 * @return array
	 */
	public static function abbreviations() {
		/**
		 * Filters title abbreviations (word => expansion).
		 *
		 * @param array $map Map.
		 */
		return (array) apply_filters(
			'dev3jm_title_abbreviations',
			array(
				'sr'         => 'Senior',
				'snr'        => 'Senior',
				'jr'         => 'Junior',
				'jnr'        => 'Junior',
				'mgr'        => 'Manager',
				'mngr'       => 'Manager',
				'eng'        => 'Engineer',
				'engr'       => 'Engineer',
				'dev'        => 'Developer',
				'devs'       => 'Developers',
				'js'         => 'JavaScript',
				'ts'         => 'TypeScript',
				'asst'       => 'Assistant',
				'assoc'      => 'Associate',
				'exec'       => 'Executive',
				'coord'      => 'Coordinator',
				'rep'        => 'Representative',
				'acct'       => 'Accountant',
				'mktg'       => 'Marketing',
				'ops'        => 'Operations',
				'hr'         => 'HR',
				'qa'         => 'QA',
				'ui'         => 'UI',
				'ux'         => 'UX',
				'it'         => 'IT',
				'seo'        => 'SEO',
				'php'        => 'PHP',
				'sql'        => 'SQL',
				'aws'        => 'AWS',
				'ios'        => 'iOS',
				'vp'         => 'VP',
				'cto'        => 'CTO',
				'ceo'        => 'CEO',
				'cfo'        => 'CFO',
				'devops'     => 'DevOps',
				'wordpress'  => 'WordPress',
				'javascript' => 'JavaScript',
				'typescript' => 'TypeScript',
				'nodejs'     => 'Node.js',
				'reactjs'    => 'React',
			)
		);
	}

	/**
	 * Hype words removed from titles.
	 *
	 * @return string[]
	 */
	public static function hype() {
		return (array) apply_filters( 'dev3jm_title_hype_words', array( 'rockstar', 'rock star', 'ninja', 'guru', 'wizard', 'unicorn', 'superstar', 'jedi', 'hero', 'urgent', 'urgently', 'hiring', 'immediate start', 'asap', 'wanted', 'needed', 'amazing', 'awesome' ) );
	}

	/**
	 * Normalises a title.
	 *
	 * @param string $raw Raw title.
	 * @return array { title, seniority, changes[], changed }
	 */
	public static function normalise( $raw ) {
		$raw     = trim( html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES, 'UTF-8' ) );
		$t       = $raw;
		$changes = array();

		// Emojis and decorative symbols.
		$clean = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}★☆✓✔✨🔥]+/u', '', $t );
		if ( $clean !== $t ) {
			$changes[] = __( 'Removed emojis', 'dev3-ai-wp-job-manager' );
			$t         = (string) $clean;
		}
		// Salary and pay stuffed into the title.
		$clean = preg_replace( '/\s*[\(\[\|\-–—,]?\s*(?:[$£€₹]\s?\d[\d,.]*\s*k?(?:\s*[-–]\s*[$£€₹]?\s?\d[\d,.]*\s*k?)?|\d[\d,.]*\s*k?\s*(?:usd|eur|gbp|inr|lpa))(?:\s*(?:\/|per)\s*\w+)?\s*[\)\]]?/iu', '', $t );
		if ( $clean !== $t ) {
			$changes[] = __( 'Moved pay out of the title (use the salary field)', 'dev3-ai-wp-job-manager' );
			$t         = (string) $clean;
		}
		// Shouting / repeated punctuation.
		$clean = preg_replace( '/([!?.*~])\1+|!+/', '', $t );
		if ( $clean !== $t ) {
			$changes[] = __( 'Removed extra punctuation', 'dev3-ai-wp-job-manager' );
			$t         = (string) $clean;
		}
		// Hype words.
		foreach ( self::hype() as $word ) {
			$clean = preg_replace( '/\b' . preg_quote( $word, '/' ) . 's?\b\s*[:\-–]?\s*/iu', '', $t );
			if ( $clean !== $t ) {
				/* translators: %s: word */
				$changes[] = sprintf( __( 'Removed "%s"', 'dev3-ai-wp-job-manager' ), $word );
				$t         = (string) $clean;
			}
		}
		// Abbreviations (also "Sr." with a dot).
		$map = self::abbreviations();
		$t   = (string) preg_replace_callback(
			'/(?<![\w.])([A-Za-z]+)(\.(?!\w))?(?![\w.])/u',
			static function ( $m ) use ( $map, &$changes ) {
				$key = strtolower( $m[1] );
				if ( isset( $map[ $key ] ) ) {
					if ( $map[ $key ] !== $m[0] && strtolower( $map[ $key ] ) !== $key ) {
						/* translators: 1: abbreviation, 2: expansion */
						$changes[] = sprintf( __( '"%1$s" → "%2$s"', 'dev3-ai-wp-job-manager' ), $m[0], $map[ $key ] );
					}
					return $map[ $key ];
				}
				return $m[0];
			},
			$t
		);
		// Case: ALL CAPS or all lower → Title Case (keeps known acronyms from the map).
		$letters = preg_replace( '/[^A-Za-z]/', '', $t );
		if ( strlen( $letters ) > 3 && ( strtoupper( $letters ) === $letters || strtolower( $letters ) === $letters ) ) {
			$t         = self::title_case( $t, $map );
			$changes[] = __( 'Fixed capitalisation', 'dev3-ai-wp-job-manager' );
		}
		// Tidy separators and spaces.
		$t       = preg_replace( '/\s*([\/|,])\s*/', '$1 ', $t );
		$t       = preg_replace( '/\s{2,}/', ' ', (string) $t );
		$t       = trim( (string) $t, " \t\n\r\0\x0B-–—|:,/" );
		$t       = str_replace( '/ ', ' / ', (string) $t );
		$t       = preg_replace( '/\s{2,}/', ' ', (string) $t );
		$emptied = '' === $t;
		if ( $emptied ) {
			$t = $raw;
		}
		$t = mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 );
		return array(
			'title'     => mb_substr( $t, 0, 120 ),
			'seniority' => self::seniority( $t ),
			'changes'   => array_values( array_unique( $changes ) ),
			'changed'   => $emptied || $t !== $raw,
		);
	}

	/**
	 * Title Case keeping acronyms.
	 *
	 * @param string $t   Text.
	 * @param array  $map Abbreviations.
	 * @return string
	 */
	private static function title_case( $t, array $map ) {
		$small = array( 'and', 'or', 'of', 'the', 'for', 'in', 'on', 'at', 'to', 'a', 'an', 'with' );
		$words = preg_split( '/(\s+)/', mb_strtolower( $t ), -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( (array) $words as $i => $w ) {
			if ( isset( $map[ $w ] ) ) {
				$out .= $map[ $w ];
			} elseif ( $i > 0 && in_array( $w, $small, true ) ) {
				$out .= $w;
			} else {
				$out .= mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_substr( $w, 1 );
			}
		}
		return $out;
	}

	/**
	 * Seniority from a title.
	 *
	 * @param string $title Title.
	 * @return string '' | entry | mid | senior | lead | executive
	 */
	public static function seniority( $title ) {
		$t = mb_strtolower( (string) $title );
		if ( preg_match( '/\b(chief|vp|vice president|director|head of|ceo|cto|cfo|coo|cmo)\b/', $t ) ) {
			return 'executive';
		}
		if ( preg_match( '/\b(lead|principal|staff|manager)\b/', $t ) ) {
			return 'lead';
		}
		if ( preg_match( '/\b(senior|sr)\b/', $t ) ) {
			return 'senior';
		}
		if ( preg_match( '/\b(junior|jr|intern|internship|trainee|graduate|entry|apprentice|assistant)\b/', $t ) ) {
			return 'entry';
		}
		if ( preg_match( '/\b(mid|intermediate|ii|iii)\b/', $t ) ) {
			return 'mid';
		}
		return '';
	}
}
