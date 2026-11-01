<?php
/**
 * Job type, category and tag suggestions: keyword rules, refined by DEV3 AI (constrained to the
 * site's existing job types and categories) when available.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Ai;

use Dev3\AiWpJobManager\Core3\Api\Prompts;
use Dev3\AiWpJobManager\Text\Suggest;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Suggester {

	/**
	 * Suggestions.
	 *
	 * @param array  $job     { title, content, ... }.
	 * @param string $context Context.
	 * @param bool   $use_ai  Allow AI.
	 * @return array { types[{id,name,slug,reason}], categories[], tags[], reason, source, meta }
	 */
	public static function run( array $job, $context = 'admin', $use_ai = true ) {
		$rules           = Suggest::rules( $job );
		$rules['reason'] = '';
		$rules['meta']   = array();
		$text            = trim( ( isset( $job['title'] ) ? $job['title'] : '' ) . ' ' . Wpjm::plain( isset( $job['content'] ) ? $job['content'] : '' ) );
		if ( ! $use_ai || '' === $text || ! Writer::ai_available( $context ) ) {
			return $rules;
		}
		$types = Wpjm::all_terms( Wpjm::TAX_TYPE );
		$cats  = Wpjm::all_terms( Wpjm::TAX_CAT );
		$pairs = static function ( array $terms ) {
			return $terms ? implode( '; ', array_map( static fn( $t ) => $t['slug'] . ': ' . $t['name'], $terms ) ) : '(none)';
		};
		$tags  = wp_list_pluck( Wpjm::all_terms( Wpjm::TAX_TAG ), 'name' );
		$res   = Prompts::run(
			Prompts_Library::SUGGEST,
			array(
				'types'      => $pairs( $types ),
				'categories' => $pairs( $cats ),
				'tags'       => $tags ? implode( ', ', array_slice( $tags, 0, 150 ) ) : '(none)',
				'listing'    => mb_substr( 'Title: ' . ( isset( $job['title'] ) ? $job['title'] : '' ) . "\n" . Wpjm::plain( isset( $job['content'] ) ? $job['content'] : '', 4000 ), 0, 4500 ),
				'language'   => Prompts_Library::language(),
			),
			$context,
			array( 'schema' => Prompts_Library::suggest_schema( wp_list_pluck( $types, 'slug' ), wp_list_pluck( $cats, 'slug' ) ) )
		);
		if ( is_wp_error( $res ) ) {
			$rules['error'] = array(
				'code'    => $res->get_error_code(),
				'message' => $res->get_error_message(),
			);
			return $rules;
		}
		$d      = is_array( $res['data'] ) ? $res['data'] : array();
		$pick   = static function ( array $terms, $slugs, $reason ) {
			$out = array();
			foreach ( (array) $slugs as $slug ) {
				foreach ( $terms as $t ) {
					if ( $t['slug'] === $slug ) {
						$out[] = array_merge( $t, array( 'reason' => $reason ) );
					}
				}
			}
			return $out;
		};
		$reason = isset( $d['reason'] ) ? sanitize_text_field( (string) $d['reason'] ) : '';
		$ai_t   = $pick( $types, isset( $d['types'] ) ? $d['types'] : array(), __( 'Suggested by DEV3 AI', 'dev3-ai-wp-job-manager' ) );
		$ai_c   = $pick( $cats, isset( $d['categories'] ) ? $d['categories'] : array(), '' );
		$tags   = array();
		foreach ( isset( $d['tags'] ) && is_array( $d['tags'] ) ? $d['tags'] : array() as $tag ) {
			$tag = trim( sanitize_text_field( (string) $tag ) );
			if ( '' !== $tag && mb_strlen( $tag ) <= 40 ) {
				$tags[ mb_strtolower( $tag ) ] = $tag;
			}
		}
		foreach ( $rules['tags'] as $tag ) {
			if ( count( $tags ) < 8 ) {
				$tags[ mb_strtolower( $tag ) ] = isset( $tags[ mb_strtolower( $tag ) ] ) ? $tags[ mb_strtolower( $tag ) ] : $tag;
			}
		}
		return array(
			'types'      => $ai_t ? array_slice( $ai_t, 0, 2 ) : $rules['types'],
			'categories' => $ai_c ? array_slice( $ai_c, 0, 2 ) : $rules['categories'],
			'tags'       => array_slice( array_values( $tags ), 0, 8 ),
			'reason'     => $reason,
			'source'     => 'ai',
			'meta'       => Prompts_Library::meta( $res ),
		);
	}
}
