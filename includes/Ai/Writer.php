<?php
/**
 * Job description writer: DEV3 AI drafts a structured job ad from notes or the current description.
 * Without AI it builds a clean outline from the same text. Either way the result is a draft.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Api\Ai;
use Dev3\AiWpJobManager\Core3\Api\Prompts;
use Dev3\AiWpJobManager\Core3\Core\Settings_Store;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Text\Title;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Writer {

	/**
	 * Whether AI can run for a context.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function ai_available( $context = 'admin' ) {
		return Ai::enabled( $context ) && '' !== Settings_Store::api_key();
	}

	/**
	 * Writer input from a saved listing.
	 *
	 * @param array  $job   Listing.
	 * @param string $notes Extra notes.
	 * @param string $tone  Tone.
	 * @return array
	 */
	public static function input_from_listing( array $job, $notes = '', $tone = '' ) {
		$current = Wpjm::plain( $job['content'], 4000 );
		return array(
			'title'    => $job['title'],
			'notes'    => trim( $notes . ( '' !== $current ? "\n" . $current : '' ) ),
			'company'  => $job['company'],
			'tagline'  => $job['company_tagline'],
			'location' => $job['location'],
			'remote'   => $job['remote'],
			'types'    => implode( ', ', wp_list_pluck( $job['types'], 'name' ) ),
			'salary'   => $job['salary'],
			'tone'     => $tone,
		);
	}

	/**
	 * Cleans raw input (REST / submit form).
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function clean_input( array $in ) {
		$text  = static function ( $key, $len ) use ( $in ) {
			return isset( $in[ $key ] ) ? mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, $len ) : '';
		};
		$tone  = isset( $in['tone'] ) && in_array( $in['tone'], Settings::TONES, true ) ? $in['tone'] : (string) Settings::get( 'writer_tone', 'professional' );
		$notes = isset( $in['notes'] ) ? (string) $in['notes'] : '';
		if ( false !== strpos( $notes, '<' ) ) {
			$notes = Wpjm::plain( $notes, 4000 );
		}
		return array(
			'title'    => $text( 'title', 160 ),
			'notes'    => mb_substr( sanitize_textarea_field( $notes ), 0, 4000 ),
			'company'  => $text( 'company', 120 ),
			'tagline'  => $text( 'tagline', 160 ),
			'location' => $text( 'location', 120 ),
			'remote'   => ! empty( $in['remote'] ),
			'types'    => $text( 'types', 120 ),
			'salary'   => $text( 'salary', 120 ),
			'tone'     => $tone,
		);
	}

	/**
	 * Draft a job ad.
	 *
	 * @param array  $in             Writer input.
	 * @param string $context        admin|submission|cron.
	 * @param bool   $force_template Skip AI.
	 * @return array|\WP_Error { title, excerpt, content, skills, seniority, sections, source, meta }
	 */
	public static function draft( array $in, $context = 'admin', $force_template = false ) {
		$input = self::clean_input( $in );
		if ( '' === $input['title'] && '' === $input['notes'] ) {
			return new \WP_Error( 'dev3jm_writer_empty', __( 'Add a job title or a few notes about the role first.', 'dev3-ai-wp-job-manager' ), array( 'status' => 400 ) );
		}
		if ( $force_template || ! self::ai_available( $context ) ) {
			return self::template( $input );
		}
		$vars = $input;
		foreach ( $vars as $k => $v ) {
			if ( is_bool( $v ) ) {
				$vars[ $k ] = $v ? 'yes' : 'no';
			} elseif ( '' === $v ) {
				$vars[ $k ] = '(not given)';
			}
		}
		$vars['language'] = Prompts_Library::language();
		$res              = Prompts::run( Prompts_Library::DESCRIBE, $vars, $context, array( 'schema' => Prompts_Library::describe_schema() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out           = self::from_sections( is_array( $res['data'] ) ? $res['data'] : array(), $input );
		$out['source'] = 'ai';
		$out['meta']   = Prompts_Library::meta( $res );
		return $out;
	}

	/**
	 * Draft from AI sections.
	 *
	 * @param array $data  Sections.
	 * @param array $input Input.
	 * @return array
	 */
	public static function from_sections( array $data, array $input ) {
		$list     = static function ( $key, $max ) use ( $data ) {
			$items = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
			return array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $items ) ) ) ), 0, $max );
		};
		$sections = array(
			'summary'          => sanitize_textarea_field( isset( $data['summary'] ) ? (string) $data['summary'] : '' ),
			'responsibilities' => $list( 'responsibilities', 10 ),
			'requirements'     => $list( 'requirements', 10 ),
			'nice_to_have'     => $list( 'nice_to_have', 6 ),
			'benefits'         => $list( 'benefits', 8 ),
		);
		$title    = isset( $data['title'] ) && '' !== trim( (string) $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : $input['title'];
		$level    = isset( $data['seniority'] ) && in_array( $data['seniority'], Title::LEVELS, true ) ? $data['seniority'] : Title::seniority( $title );
		$excerpt  = isset( $data['excerpt'] ) ? mb_substr( sanitize_text_field( (string) $data['excerpt'] ), 0, 200 ) : '';
		return array(
			'title'     => mb_substr( $title, 0, 160 ),
			'excerpt'   => '' === $excerpt ? mb_substr( $sections['summary'], 0, 200 ) : $excerpt,
			'content'   => self::to_html( $sections ),
			'skills'    => $list( 'skills', 10 ),
			'seniority' => $level,
			'sections'  => $sections,
		);
	}

	/**
	 * Non-AI outline from the notes: sentences become the summary, bullet lines become lists.
	 *
	 * @param array $input Clean input.
	 * @return array
	 */
	public static function template( array $input ) {
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $input['notes'] );
		$bullets = array();
		$prose   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^([-*•·]|\d+[.)])\s*(.+)$/u', $line, $m ) ) {
				$bullets[] = rtrim( trim( $m[2] ), '.' ) . '.';
			} elseif ( preg_match( '/:\s*$/', $line ) ) {
				continue; // Headings from an old description.
			} else {
				$prose[] = $line;
			}
		}
		// Long prose with no bullets: split sentences that look like duties or requirements.
		if ( ! $bullets && count( $prose ) <= 2 ) {
			$sentences = preg_split( '/(?<=[.!?])\s+/', implode( ' ', $prose ) );
			$keep      = array();
			foreach ( (array) $sentences as $s ) {
				if ( preg_match( '/\b(you will|you\'ll|responsible for|years|experience|must|required|needed|knowledge|we offer|benefits?)\b/i', $s ) ) {
					$bullets[] = trim( $s );
				} else {
					$keep[] = $s;
				}
			}
			$prose = array( implode( ' ', $keep ) );
		}
		$req  = array();
		$resp = array();
		$ben  = array();
		foreach ( $bullets as $b ) {
			if ( preg_match( '/\b(years?|experience|degree|knowledge|skills?|proficien|familiar|fluent|must|required|needed)\b/i', $b ) ) {
				$req[] = $b;
			} elseif ( preg_match( '/\b(salary|bonus|holiday|vacation|pto|insurance|health|pension|equity|flexible|budget|leave|we offer|free|discount|perks?|allowance)\b/i', $b ) ) {
				$ben[] = $b;
			} else {
				$resp[] = preg_replace( '/^(you will|you\'ll)\s+/i', '', $b );
			}
		}
		$company = '' !== $input['company'] ? $input['company'] : get_bloginfo( 'name' );
		$title   = '' !== $input['title'] ? Title::normalise( $input['title'] )['title'] : __( 'New role', 'dev3-ai-wp-job-manager' );
		$prose   = array_filter( array_map( 'trim', $prose ) );
		$summary = $prose ? implode( ' ', $prose ) : sprintf(
			/* translators: 1: company, 2: job title */
			__( '%1$s is hiring a %2$s. You will join a friendly team and do meaningful work from day one.', 'dev3-ai-wp-job-manager' ),
			$company,
			$title
		);
		if ( ! $resp ) {
			$resp = array( __( 'Own your area of work from planning to delivery.', 'dev3-ai-wp-job-manager' ), __( 'Work closely with your team and share what you learn.', 'dev3-ai-wp-job-manager' ) );
		}
		$resp     = array_map( static fn( $r ) => mb_strtoupper( mb_substr( $r, 0, 1 ) ) . mb_substr( $r, 1 ), $resp );
		$sections = array(
			'summary'          => $summary,
			'responsibilities' => array_slice( $resp, 0, 10 ),
			'requirements'     => array_slice( $req, 0, 10 ),
			'nice_to_have'     => array(),
			'benefits'         => array_slice( $ben, 0, 8 ),
		);
		// The outline already uses neutral wording: apply the word list's replacements.
		$content = self::to_html( $sections );
		$fixes   = array_filter( Inclusive::rule_hits( Wpjm::plain( $content ) ), static fn( $h ) => '' !== $h['suggestion'] );
		if ( $fixes ) {
			$content = Inclusive::apply( $content, $fixes );
		}
		return array(
			'title'     => $title,
			'excerpt'   => mb_substr( $summary, 0, 200 ),
			'content'   => $content,
			'skills'    => \Dev3\AiWpJobManager\Text\Suggest::skills_in( $input['title'] . ' ' . $input['notes'], 10 ),
			'seniority' => Title::seniority( $title ),
			'sections'  => $sections,
			'source'    => 'template',
			'meta'      => array(),
		);
	}

	/**
	 * Sections → job HTML (WPJM descriptions are classic HTML).
	 *
	 * @param array $s Sections.
	 * @return string
	 */
	public static function to_html( array $s ) {
		$html = '';
		if ( '' !== $s['summary'] ) {
			foreach ( preg_split( '/\n\s*\n/', $s['summary'] ) as $para ) {
				$html .= '<p>' . esc_html( trim( $para ) ) . "</p>\n";
			}
		}
		$heads = array(
			'responsibilities' => __( 'What you will do', 'dev3-ai-wp-job-manager' ),
			'requirements'     => __( 'What you bring', 'dev3-ai-wp-job-manager' ),
			'nice_to_have'     => __( 'Nice to have', 'dev3-ai-wp-job-manager' ),
			'benefits'         => __( 'What we offer', 'dev3-ai-wp-job-manager' ),
		);
		foreach ( $heads as $key => $label ) {
			if ( empty( $s[ $key ] ) ) {
				continue;
			}
			$html .= '<h3>' . esc_html( $label ) . "</h3>\n<ul>\n";
			foreach ( $s[ $key ] as $item ) {
				$html .= '<li>' . esc_html( $item ) . "</li>\n";
			}
			$html .= "</ul>\n";
		}
		return $html;
	}
}
