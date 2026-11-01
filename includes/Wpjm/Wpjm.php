<?php
/**
 * WP Job Manager bridge: detection, listing reads and writes. WPJM owns the data; we only use its
 * documented post type, taxonomies and meta keys.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers.
 */
class Wpjm {

	const PT       = 'job_listing';
	const TAX_TYPE = 'job_listing_type';
	const TAX_CAT  = 'job_listing_category';
	const TAX_TAG  = 'job_listing_tag';
	const MIN      = '1.36.0';

	/**
	 * Whether WP Job Manager is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WP_Job_Manager' ) || post_type_exists( self::PT );
	}

	/**
	 * WPJM version or ''.
	 *
	 * @return string
	 */
	public static function version() {
		return defined( 'JOB_MANAGER_VERSION' ) ? (string) JOB_MANAGER_VERSION : '';
	}

	/**
	 * Status for the dashboard.
	 *
	 * @return array
	 */
	public static function status() {
		$version = self::version();
		return array(
			'active'        => self::active(),
			'version'       => $version,
			'supported'     => self::active() && ( '' === $version || version_compare( $version, self::MIN, '>=' ) ),
			'salary'        => (bool) get_option( 'job_manager_enable_salary' ),
			'types'         => taxonomy_exists( self::TAX_TYPE ),
			'categories'    => taxonomy_exists( self::TAX_CAT ),
			'tags'          => taxonomy_exists( self::TAX_TAG ),
			'tags_ours'     => Tags::ours(),
			'resumes'       => Resumes::active(),
			'resumes_class' => class_exists( 'WP_Resume_Manager' ),
			'install_url'   => admin_url( 'plugin-install.php?s=wp-job-manager&tab=search&type=term' ),
			'jobs_url'      => admin_url( 'edit.php?post_type=' . self::PT ),
		);
	}

	/**
	 * Listing statuses we work with.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'publish', 'pending', 'draft', 'expired', 'preview', 'future' );
	}

	/**
	 * Term list of a post.
	 *
	 * @param int    $id  Post.
	 * @param string $tax Taxonomy.
	 * @return array [ { id, name, slug } ]
	 */
	public static function terms( $id, $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$terms = get_the_terms( $id, $tax );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array(
				'id'   => (int) $t->term_id,
				'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'slug' => $t->slug,
			);
		}
		return $out;
	}

	/**
	 * All terms of a taxonomy (for pickers and AI constraints).
	 *
	 * @param string $tax Taxonomy.
	 * @return array [ { id, name, slug } ]
	 */
	public static function all_terms( $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array(
				'id'   => (int) $t->term_id,
				'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'slug' => $t->slug,
			);
		}
		return $out;
	}

	/**
	 * Normalised listing.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return array|null
	 */
	public static function get( $post ) {
		$post = get_post( $post );
		if ( ! $post || self::PT !== $post->post_type ) {
			return null;
		}
		$id = (int) $post->ID;
		$m  = static function ( $key ) use ( $id ) {
			return (string) get_post_meta( $id, $key, true );
		};
		return array(
			'id'              => $id,
			'title'           => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'content'         => (string) $post->post_content,
			'status'          => $post->post_status,
			'date'            => $post->post_date_gmt,
			'modified'        => $post->post_modified_gmt,
			'author'          => (int) $post->post_author,
			'company'         => $m( '_company_name' ),
			'company_tagline' => $m( '_company_tagline' ),
			'website'         => $m( '_company_website' ),
			'location'        => $m( '_job_location' ),
			'remote'          => (bool) get_post_meta( $id, '_remote_position', true ),
			'salary'          => $m( '_job_salary' ),
			'salary_currency' => $m( '_job_salary_currency' ),
			'salary_unit'     => $m( '_job_salary_unit' ),
			'salary_min'      => '' === $m( '_dev3jm_salary_min' ) ? null : (float) $m( '_dev3jm_salary_min' ),
			'salary_max'      => '' === $m( '_dev3jm_salary_max' ) ? null : (float) $m( '_dev3jm_salary_max' ),
			'seniority'       => $m( '_dev3jm_seniority' ),
			'expires'         => $m( '_job_expires' ),
			'filled'          => (bool) get_post_meta( $id, '_filled', true ),
			'featured'        => (bool) get_post_meta( $id, '_featured', true ),
			'application'     => $m( '_application' ),
			'types'           => self::terms( $id, self::TAX_TYPE ),
			'categories'      => self::terms( $id, self::TAX_CAT ),
			'tags'            => self::terms( $id, self::TAX_TAG ),
			'quality'         => '' === $m( '_dev3jm_quality' ) ? null : (int) $m( '_dev3jm_quality' ),
			'quality_issues'  => (array) get_post_meta( $id, '_dev3jm_quality_issues', true ),
			'inclusive'       => (int) get_post_meta( $id, '_dev3jm_inclusive', true ),
			'edit_url'        => (string) get_edit_post_link( $id, 'raw' ),
			'view_url'        => (string) get_permalink( $id ),
		);
	}

	/**
	 * Plain-text summary of a listing (prompts, embeddings, matching).
	 *
	 * @param array $job Listing.
	 * @return string
	 */
	public static function to_text( array $job ) {
		$lines = array(
			'Title: ' . $job['title'],
			'Company: ' . ( '' !== $job['company'] ? $job['company'] : '(not given)' ),
			'Location: ' . ( '' !== $job['location'] ? $job['location'] : '(not given)' ) . ( $job['remote'] ? ' (remote)' : '' ),
			'Job type: ' . implode( ', ', wp_list_pluck( $job['types'], 'name' ) ),
			'Salary: ' . ( '' !== $job['salary'] ? $job['salary'] : '(not given)' ),
		);
		if ( $job['tags'] ) {
			$lines[] = 'Tags: ' . implode( ', ', wp_list_pluck( $job['tags'], 'name' ) );
		}
		$lines[] = 'Description: ' . self::plain( $job['content'], 5000 );
		return implode( "\n", $lines );
	}

	/**
	 * HTML → plain text with list/heading breaks.
	 *
	 * @param string $html HTML.
	 * @param int    $max  Max chars.
	 * @return string
	 */
	public static function plain( $html, $max = 8000 ) {
		$html = (string) preg_replace( '/<li\b[^>]*>/i', "\n- ", (string) $html );
		$html = str_replace( array( '</li>', '</p>', '</h2>', '</h3>', '</h4>', '<br>', '<br />' ), array( "\n", "</p>\n", ": </h2>\n", ": </h3>\n", ": </h4>\n", "\n", "\n" ), (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\s*\n\s*/', "\n", (string) $text );
		$text = preg_replace( '/\.\s*\./', '.', (string) $text );
		return mb_substr( trim( (string) $text ), 0, $max );
	}

	/**
	 * Resolves term ids/slugs/names to existing term ids of a taxonomy (optionally creating tags).
	 *
	 * @param string $tax    Taxonomy.
	 * @param array  $values Ids, slugs or names.
	 * @param bool   $create Create missing terms (tags only).
	 * @return int[]
	 */
	public static function resolve_terms( $tax, array $values, $create = false ) {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$ids = array();
		foreach ( $values as $v ) {
			if ( is_numeric( $v ) ) {
				$term = get_term( (int) $v, $tax );
			} else {
				$v    = trim( sanitize_text_field( (string) $v ) );
				$term = '' === $v ? null : get_term_by( 'slug', sanitize_title( $v ), $tax );
				if ( ! $term && '' !== $v ) {
					$term = get_term_by( 'name', $v, $tax );
				}
				if ( ! $term && $create && '' !== $v ) {
					$made = wp_insert_term( mb_substr( $v, 0, 60 ), $tax );
					$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], $tax );
				}
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Applies accepted fields to a listing. Only keys present are written.
	 *
	 * @param int   $id     Listing.
	 * @param array $fields { title, content, salary{min,max,currency,unit,label}, seniority, types[], tags[], categories[] }.
	 * @return array|\WP_Error Updated listing.
	 */
	public static function apply( $id, array $fields ) {
		$job = self::get( $id );
		if ( ! $job ) {
			return new \WP_Error( 'dev3jm_not_found', __( 'Job listing not found.', 'dev3-ai-wp-job-manager' ), array( 'status' => 404 ) );
		}
		$post = array( 'ID' => $id );
		if ( isset( $fields['title'] ) && '' !== trim( (string) $fields['title'] ) ) {
			$post['post_title'] = mb_substr( sanitize_text_field( (string) $fields['title'] ), 0, 200 );
		}
		if ( isset( $fields['content'] ) && '' !== trim( (string) $fields['content'] ) ) {
			$post['post_content'] = wp_kses_post( (string) $fields['content'] );
		}
		if ( count( $post ) > 1 ) {
			$res = wp_update_post( wp_slash( $post ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( isset( $fields['salary'] ) && is_array( $fields['salary'] ) ) {
			\Dev3\AiWpJobManager\Text\Salary::write( $id, $fields['salary'] );
		}
		if ( isset( $fields['seniority'] ) ) {
			update_post_meta( $id, '_dev3jm_seniority', sanitize_key( (string) $fields['seniority'] ) );
		}
		if ( isset( $fields['types'] ) && is_array( $fields['types'] ) && taxonomy_exists( self::TAX_TYPE ) ) {
			$ids = self::resolve_terms( self::TAX_TYPE, $fields['types'] );
			if ( $ids ) {
				wp_set_object_terms( $id, $ids, self::TAX_TYPE );
			}
		}
		if ( isset( $fields['tags'] ) && is_array( $fields['tags'] ) && taxonomy_exists( self::TAX_TAG ) ) {
			wp_set_object_terms( $id, self::resolve_terms( self::TAX_TAG, $fields['tags'], true ), self::TAX_TAG );
		}
		if ( isset( $fields['categories'] ) && is_array( $fields['categories'] ) && taxonomy_exists( self::TAX_CAT ) ) {
			$ids = self::resolve_terms( self::TAX_CAT, $fields['categories'] );
			if ( $ids ) {
				wp_set_object_terms( $id, $ids, self::TAX_CAT );
			}
		}
		\Dev3\AiWpJobManager\Quality\Quality::refresh( $id );
		/**
		 * Fires after DEV3 AI changes were accepted for a listing.
		 *
		 * @param int   $id     Listing.
		 * @param array $fields Accepted fields.
		 */
		do_action( 'dev3jm_listing_applied', $id, $fields );
		return self::get( $id );
	}
}
