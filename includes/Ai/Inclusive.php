<?php
/**
 * Inclusive-language check for job ads: a built-in word list (works without AI) plus an optional
 * DEV3 AI review. It only suggests; the employer chooses which fixes to apply.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Api\Ai;
use Dev3\AiWpJobManager\Core3\Api\Prompts;
use Dev3\AiWpJobManager\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Inclusive {

	/**
	 * Word list: regex => [ reason key, suggestion ].
	 *
	 * @return array
	 */
	public static function rules() {
		$age    = __( 'Age-coded: it can put off experienced candidates.', 'dev3-ai-wp-job-manager' );
		$gender = __( 'Gender-coded: use neutral words so everyone feels invited.', 'dev3-ai-wp-job-manager' );
		$jargon = __( 'Jargon that reads as exclusive; say what the skill is.', 'dev3-ai-wp-job-manager' );
		$origin = __( 'Can exclude people by origin; ask for the level you need.', 'dev3-ai-wp-job-manager' );
		$able   = __( 'Can exclude disabled candidates; describe the actual task.', 'dev3-ai-wp-job-manager' );
		$rules  = array(
			'/\b(young|youthful)\b/i'               => array( $age, __( 'motivated', 'dev3-ai-wp-job-manager' ) ),
			'/\bdigital natives?\b/i'               => array( $age, __( 'comfortable with digital tools', 'dev3-ai-wp-job-manager' ) ),
			'/\b(recent|fresh) graduates? only\b/i' => array( $age, __( 'early-career candidates welcome', 'dev3-ai-wp-job-manager' ) ),
			'/\bunder \d{2} years( old)?\b/i'       => array( $age, '' ),
			'/\bmax(imum)? age\b[^.]*/i'            => array( $age, '' ),
			'/\b(rock ?stars?|ninjas?|gurus?|wizards?|unicorns?)\b/i' => array( $jargon, __( 'expert', 'dev3-ai-wp-job-manager' ) ),
			'/\bhe\/she\b|\bs\/he\b/i'              => array( $gender, __( 'you', 'dev3-ai-wp-job-manager' ) ),
			'/\bhis\/her\b/i'                       => array( $gender, __( 'your', 'dev3-ai-wp-job-manager' ) ),
			'/\b(salesman|salesmen)\b/i'            => array( $gender, __( 'salesperson', 'dev3-ai-wp-job-manager' ) ),
			'/\bchairman\b/i'                       => array( $gender, __( 'chair', 'dev3-ai-wp-job-manager' ) ),
			'/\bmanpower\b/i'                       => array( $gender, __( 'workforce', 'dev3-ai-wp-job-manager' ) ),
			'/\b(guys|girls)\b/i'                   => array( $gender, __( 'team', 'dev3-ai-wp-job-manager' ) ),
			'/\b(aggressive|dominant|dominate)\b/i' => array( $gender, __( 'ambitious', 'dev3-ai-wp-job-manager' ) ),
			'/\bnative (english|german|french|spanish) speakers?\b/i' => array( $origin, __( 'fluent English speaker', 'dev3-ai-wp-job-manager' ) ),
			'/\bculture fit\b/i'                    => array( __( 'Vague and can hide bias; name the values you mean.', 'dev3-ai-wp-job-manager' ), __( 'shares our values', 'dev3-ai-wp-job-manager' ) ),
			'/\bable-bodied\b/i'                    => array( $able, '' ),
			'/\b(energetic|high[\s-]energy)\b/i'    => array( $age, __( 'motivated', 'dev3-ai-wp-job-manager' ) ),
			'/\bhe will\b/i'                        => array( $gender, __( 'you will', 'dev3-ai-wp-job-manager' ) ),
			'/\bhis or her\b/i'                     => array( $gender, __( 'your', 'dev3-ai-wp-job-manager' ) ),
			'/\b(male|female|men|women) only\b/i'   => array( $gender, '' ),
			'/\bcleaning lady\b/i'                  => array( $gender, __( 'cleaner', 'dev3-ai-wp-job-manager' ) ),
			'/\bcrazy\b|\binsane\b/i'               => array( $able, __( 'exciting', 'dev3-ai-wp-job-manager' ) ),
			'/\bmust have (a )?(clean )?driving licen[cs]e\b/i' => array( $able, __( 'a driving licence is helpful if the role needs travel', 'dev3-ai-wp-job-manager' ) ),
			'/\bwork hard,? play hard\b/i'          => array( __( 'Suggests long hours; describe how the team actually works.', 'dev3-ai-wp-job-manager' ), __( 'we care about doing great work', 'dev3-ai-wp-job-manager' ) ),
		);
		/**
		 * Filters the inclusive-language word list.
		 *
		 * @param array $rules regex => [ reason, suggestion ].
		 */
		return apply_filters( 'dev3jm_inclusive_rules', $rules );
	}

	/**
	 * Rule-based hits in a text.
	 *
	 * @param string $text Plain text.
	 * @return array [ { phrase, reason, suggestion, source } ]
	 */
	public static function rule_hits( $text ) {
		$hits = array();
		foreach ( self::rules() as $pattern => $conf ) {
			if ( preg_match_all( $pattern, $text, $m ) ) {
				foreach ( array_unique( $m[0] ) as $phrase ) {
					$hits[ mb_strtolower( $phrase ) ] = array(
						'phrase'     => $phrase,
						'reason'     => $conf[0],
						'suggestion' => $conf[1],
						'source'     => 'rule',
					);
				}
			}
		}
		return array_values( $hits );
	}

	/**
	 * Full check.
	 *
	 * @param string $html    Job HTML or text.
	 * @param string $context Context.
	 * @param bool   $use_ai  Ask DEV3 AI too (when available).
	 * @return array|\WP_Error { issues, source, meta }
	 */
	public static function check( $html, $context = 'admin', $use_ai = true ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( array( '</li>', '</p>', '</h3>' ), array( '. </li>', ' </p>', ': </h3>' ), (string) $html ) ) ) );
		$text = mb_substr( $text, 0, 8000 );
		if ( '' === $text ) {
			return new \WP_Error( 'dev3jm_inclusive_empty', __( 'Write the job description first.', 'dev3-ai-wp-job-manager' ), array( 'status' => 400 ) );
		}
		$issues = array();
		foreach ( self::rule_hits( $text ) as $hit ) {
			$issues[ mb_strtolower( $hit['phrase'] ) ] = $hit;
		}
		$meta   = array();
		$source = 'rules';
		if ( $use_ai && Ai::enabled( $context ) && '' !== Settings_Store::api_key() ) {
			$res = Prompts::run(
				Prompts_Library::INCLUSIVE,
				array(
					'text'      => $text,
					'rule_hits' => $issues ? implode( '; ', array_keys( $issues ) ) : '(none)',
					'language'  => Prompts_Library::language(),
				),
				$context
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$source = 'ai';
			$meta   = array(
				'model'  => $res['model'],
				'tokens' => isset( $res['usage']['total'] ) ? (int) $res['usage']['total'] : 0,
				'cached' => ! empty( $res['cached'] ),
			);
			$list   = isset( $res['data']['issues'] ) && is_array( $res['data']['issues'] ) ? $res['data']['issues'] : array();
			foreach ( $list as $row ) {
				$phrase = isset( $row['phrase'] ) ? trim( sanitize_text_field( (string) $row['phrase'] ) ) : '';
				// Keep only phrases that really appear in the ad, so a fix can be applied.
				if ( '' === $phrase || false === mb_stripos( $text, $phrase ) ) {
					continue;
				}
				$key = mb_strtolower( $phrase );
				if ( isset( $issues[ $key ] ) ) {
					continue;
				}
				$issues[ $key ] = array(
					'phrase'     => $phrase,
					'reason'     => isset( $row['reason'] ) ? sanitize_text_field( (string) $row['reason'] ) : '',
					'suggestion' => isset( $row['suggestion'] ) ? sanitize_text_field( (string) $row['suggestion'] ) : '',
					'source'     => 'ai',
				);
			}
		}
		return array(
			'issues' => array_values( $issues ),
			'source' => $source,
			'meta'   => $meta,
		);
	}

	/**
	 * Applies accepted fixes (case-insensitive; keeps a leading capital).
	 *
	 * @param string $html  HTML.
	 * @param array  $fixes [ { phrase, suggestion } ].
	 * @return string
	 */
	public static function apply( $html, array $fixes ) {
		foreach ( $fixes as $fix ) {
			$phrase = isset( $fix['phrase'] ) ? (string) $fix['phrase'] : '';
			if ( '' === $phrase ) {
				continue;
			}
			$replacement = isset( $fix['suggestion'] ) ? (string) $fix['suggestion'] : '';
			$html        = (string) preg_replace_callback(
				'/' . preg_quote( $phrase, '/' ) . '/iu',
				static function ( $m ) use ( $replacement ) {
					if ( '' === $replacement ) {
						return '';
					}
					$first = mb_substr( $m[0], 0, 1 );
					return mb_strtoupper( $first ) === $first && mb_strtolower( $first ) !== $first ? mb_strtoupper( mb_substr( $replacement, 0, 1 ) ) . mb_substr( $replacement, 1 ) : $replacement;
				},
				$html
			);
		}
		return preg_replace( '/ {2,}/', ' ', $html );
	}
}
