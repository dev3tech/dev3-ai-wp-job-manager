<?php
/**
 * Salary + title normalisation: rules first, DEV3 AI only when the rules cannot read the salary
 * (or when asked). Returns a draft; nothing is written here.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Api\Prompts;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Text\Salary;
use Dev3\AiWpJobManager\Text\Title;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Normaliser {

	/**
	 * Normalise title + salary.
	 *
	 * @param array  $in      { title, salary, salary_currency, salary_unit, content }.
	 * @param string $context Context.
	 * @param bool   $use_ai  Allow AI.
	 * @param bool   $force   Always ask AI (when available).
	 * @return array { title, seniority, salary{ok,min,max,currency,unit,label}, reading, changes[], negotiable, source, meta }
	 */
	public static function run( array $in, $context = 'admin', $use_ai = true, $force = false ) {
		$title    = Title::normalise( isset( $in['title'] ) ? $in['title'] : '' );
		$salary   = Salary::parse( isset( $in['salary'] ) ? $in['salary'] : '', isset( $in['salary_currency'] ) ? $in['salary_currency'] : '', isset( $in['salary_unit'] ) ? $in['salary_unit'] : '' );
		$out      = array(
			'title'      => $title['title'],
			'seniority'  => $title['seniority'],
			'salary'     => self::salary_out( $salary ),
			'reading'    => Salary::describe( $salary ),
			'changes'    => $title['changes'],
			'negotiable' => $salary['negotiable'],
			'guessed'    => $salary['guessed_unit'],
			'source'     => 'rules',
			'meta'       => array(),
		);
		$has_text = '' !== trim( (string) ( isset( $in['salary'] ) ? $in['salary'] : '' ) );
		$need_ai  = $force || ( $has_text && ! $salary['ok'] && ! $salary['negotiable'] ) || $salary['guessed_unit'];
		if ( ! $use_ai || ! $need_ai || ! Writer::ai_available( $context ) ) {
			return $out;
		}
		$res = Prompts::run(
			Prompts_Library::NORMALISE,
			array(
				'title'           => isset( $in['title'] ) ? mb_substr( sanitize_text_field( (string) $in['title'] ), 0, 200 ) : '',
				'salary'          => isset( $in['salary'] ) ? mb_substr( sanitize_text_field( (string) $in['salary'] ), 0, 200 ) : '',
				'salary_currency' => isset( $in['salary_currency'] ) ? sanitize_text_field( (string) $in['salary_currency'] ) : '',
				'salary_unit'     => isset( $in['salary_unit'] ) ? sanitize_text_field( (string) $in['salary_unit'] ) : '',
				'rules'           => wp_json_encode( array_intersect_key( $salary, array_flip( array( 'min', 'max', 'currency', 'unit' ) ) ) ),
				'excerpt'         => Wpjm::plain( isset( $in['content'] ) ? (string) $in['content'] : '', 600 ),
				'currency'        => Settings::currency(),
			),
			$context
		);
		if ( is_wp_error( $res ) ) {
			// Keep the rules result; the UI shows the error next to it.
			$out['error'] = array(
				'code'    => $res->get_error_code(),
				'message' => $res->get_error_message(),
			);
			return $out;
		}
		$d = is_array( $res['data'] ) ? $res['data'] : array();
		if ( isset( $d['title'] ) && '' !== trim( (string) $d['title'] ) ) {
			$out['title'] = mb_substr( sanitize_text_field( (string) $d['title'] ), 0, 120 );
		}
		if ( isset( $d['seniority'] ) && in_array( $d['seniority'], Title::LEVELS, true ) ) {
			$out['seniority'] = $d['seniority'];
		}
		$min = isset( $d['salary_min'] ) ? (float) $d['salary_min'] : 0;
		$max = isset( $d['salary_max'] ) ? (float) $d['salary_max'] : 0;
		if ( $min > 0 || $max > 0 ) {
			$clean = Salary::clean(
				array(
					'min'      => $min > 0 ? $min : null,
					'max'      => $max > 0 ? $max : null,
					'currency' => isset( $d['currency'] ) ? $d['currency'] : '',
					'unit'     => isset( $d['unit'] ) && '' !== $d['unit'] ? $d['unit'] : ( $salary['unit'] ? $salary['unit'] : 'YEAR' ),
				)
			);
			if ( $clean ) {
				$out['salary']  = self::salary_out( $clean );
				$out['reading'] = Salary::describe( $clean );
				$out['guessed'] = false;
			}
		}
		if ( isset( $d['note'] ) && '' !== trim( (string) $d['note'] ) ) {
			$out['changes'][] = sanitize_text_field( (string) $d['note'] );
		}
		$out['source'] = 'ai';
		$out['meta']   = Prompts_Library::meta( $res );
		return $out;
	}

	/**
	 * Salary draft shape.
	 *
	 * @param array $s Parsed.
	 * @return array|null
	 */
	private static function salary_out( array $s ) {
		if ( empty( $s['ok'] ) ) {
			return null;
		}
		return array(
			'min'      => $s['min'],
			'max'      => $s['max'],
			'currency' => $s['currency'],
			'unit'     => $s['unit'],
			'label'    => $s['label'],
		);
	}
}
