<?php
/**
 * Admin REST routes (dev3jm/v1): listings, AI drafts, bulk improve, review queue, matching.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Rest;

use Dev3\AiWpJobManager\Ai\Improver;
use Dev3\AiWpJobManager\Ai\Inclusive;
use Dev3\AiWpJobManager\Ai\Matcher;
use Dev3\AiWpJobManager\Ai\Normaliser;
use Dev3\AiWpJobManager\Ai\Suggester;
use Dev3\AiWpJobManager\Ai\Writer;
use Dev3\AiWpJobManager\Core3\Api\Ai;
use Dev3\AiWpJobManager\Core3\Core\Jobs;
use Dev3\AiWpJobManager\Core3\Rest\Base_Controller;
use Dev3\AiWpJobManager\Data\Drafts;
use Dev3\AiWpJobManager\Quality\Quality;
use Dev3\AiWpJobManager\Wpjm\Resumes;
use Dev3\AiWpJobManager\Wpjm\Schema_Enhancer;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3jm/v1.
 */
class Rest_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage   = array( $this, 'can_manage' );
		$generate = array( $this, 'can_generate' );
		$id_arg   = array(
			'id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
		$routes   = array(
			'/overview'                   => array( 'GET', 'overview', $manage ),
			'/terms'                      => array( 'GET', 'terms', $generate ),
			'/listings'                   => array( 'GET', 'listings', $manage ),
			'/listings/(?P<id>\d+)'       => array( 'GET', 'listing', $generate ),
			'/listings/(?P<id>\d+)/apply' => array( 'POST', 'apply', $generate ),
			'/ai/write'                   => array( 'POST', 'ai_write', $generate ),
			'/ai/inclusive'               => array( 'POST', 'ai_inclusive', $generate ),
			'/ai/normalise'               => array( 'POST', 'ai_normalise', $generate ),
			'/ai/suggest'                 => array( 'POST', 'ai_suggest', $generate ),
			'/bulk'                       => array( 'POST', 'bulk_start', $manage ),
			'/bulk/recent'                => array( 'GET', 'bulk_recent', $manage ),
			'/drafts'                     => array( 'GET', 'drafts', $manage ),
			'/drafts/(?P<id>\d+)/accept'  => array( 'POST', 'draft_accept', $manage ),
			'/drafts/(?P<id>\d+)/reject'  => array( 'POST', 'draft_reject', $manage ),
			'/drafts/accept-safe'         => array( 'POST', 'drafts_accept_safe', $manage ),
			'/matching'                   => array( 'GET', 'matching_jobs', $manage ),
			'/matching/(?P<id>\d+)'       => array( 'GET', 'matching', $manage ),
			'/matching/(?P<id>\d+)/score' => array( 'POST', 'matching_score', $manage ),
		);
		foreach ( $routes as $path => $r ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $r[0],
					'callback'            => array( $this, $r[1] ),
					'permission_callback' => $r[2],
					'args'                => false !== strpos( $path, '(?P<id>' ) ? $id_arg : array(),
				)
			);
		}
	}

	/**
	 * Listing for an id param with an edit check.
	 *
	 * @param int $id Id.
	 * @return array|\WP_Error
	 */
	private function editable( $id ) {
		$job = Wpjm::get( (int) $id );
		if ( ! $job ) {
			return new \WP_Error( 'dev3jm_not_found', __( 'Job listing not found.', 'dev3-ai-wp-job-manager' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $job['id'] ) ) {
			return new \WP_Error( 'dev3jm_forbidden', __( 'You cannot edit this listing.', 'dev3-ai-wp-job-manager' ), array( 'status' => 403 ) );
		}
		return $job;
	}

	/**
	 * Listing fields from the request: a saved listing (id) merged with unsaved edits sent along.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function input_job( \WP_REST_Request $request ) {
		$base = array(
			'id'              => 0,
			'title'           => '',
			'content'         => '',
			'company'         => '',
			'company_tagline' => '',
			'location'        => '',
			'remote'          => false,
			'salary'          => '',
			'salary_currency' => '',
			'salary_unit'     => '',
			'seniority'       => '',
			'types'           => array(),
			'categories'      => array(),
			'tags'            => array(),
		);
		if ( $request['id'] ) {
			$job = $this->editable( $request['id'] );
			if ( is_wp_error( $job ) ) {
				return $job;
			}
			$base = $job;
		}
		foreach ( array( 'title', 'company', 'location', 'salary', 'salary_currency', 'salary_unit' ) as $k ) {
			if ( null !== $request[ $k ] ) {
				$base[ $k ] = sanitize_text_field( (string) $request[ $k ] );
			}
		}
		if ( null !== $request['content'] ) {
			$base['content'] = wp_kses_post( (string) $request['content'] );
		}
		return $base;
	}

	/**
	 * GET /overview.
	 *
	 * @return \WP_REST_Response
	 */
	public function overview() {
		global $wpdb;
		$status = Wpjm::status();
		$counts = array(
			'listings'  => 0,
			'good'      => 0,
			'fair'      => 0,
			'poor'      => 0,
			'unscored'  => 0,
			'no_salary' => 0,
			'inclusive' => 0,
			'average'   => 0,
		);
		if ( post_type_exists( Wpjm::PT ) ) {
			$ids = get_posts(
				array(
					'post_type'      => Wpjm::PT,
					'post_status'    => array( 'publish', 'pending', 'draft', 'expired' ),
					'posts_per_page' => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
					'fields'         => 'ids',
				)
			);
			$sum = 0;
			$n   = 0;
			foreach ( $ids as $id ) {
				++$counts['listings'];
				$q = get_post_meta( $id, '_dev3jm_quality', true );
				if ( '' === $q ) {
					++$counts['unscored'];
					continue;
				}
				$sum += (int) $q;
				++$n;
				++$counts[ Quality::grade( (int) $q ) ];
				if ( in_array( 'salary', (array) get_post_meta( $id, '_dev3jm_quality_issues', true ), true ) ) {
					++$counts['no_salary'];
				}
				if ( (int) get_post_meta( $id, '_dev3jm_inclusive', true ) > 0 ) {
					++$counts['inclusive'];
				}
			}
			$counts['average'] = $n ? (int) round( $sum / $n ) : 0;
		}
		$recent = array();
		foreach ( Jobs::recent( Improver::JOB, 1 ) as $j ) {
			$recent[] = $j;
		}
		unset( $wpdb );
		return $this->respond(
			array(
				'wpjm'    => $status,
				'counts'  => $counts,
				'drafts'  => Drafts::counts(),
				'resumes' => Resumes::count(),
				'ai'      => Ai::status(),
				'bulk'    => $recent ? $recent[0] : null,
			)
		);
	}

	/**
	 * GET /terms.
	 *
	 * @return \WP_REST_Response
	 */
	public function terms() {
		return $this->respond(
			array(
				'types'      => Wpjm::all_terms( Wpjm::TAX_TYPE ),
				'categories' => Wpjm::all_terms( Wpjm::TAX_CAT ),
				'tags'       => Wpjm::all_terms( Wpjm::TAX_TAG ),
				'has_tags'   => taxonomy_exists( Wpjm::TAX_TAG ),
				'units'      => array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ),
				'currency'   => \Dev3\AiWpJobManager\Settings::currency(),
			)
		);
	}

	/**
	 * Row for tables.
	 *
	 * @param array $job Listing.
	 * @return array
	 */
	private function row( array $job ) {
		if ( null === $job['quality'] ) {
			$q                     = Quality::refresh( $job['id'] );
			$job['quality']        = $q['score'];
			$job['quality_issues'] = $q['issues'];
			$job['inclusive']      = $q['inclusive'];
		}
		$draft = Drafts::pending_for( $job['id'] );
		return array(
			'id'        => $job['id'],
			'title'     => $job['title'],
			'status'    => $job['status'],
			'company'   => $job['company'],
			'location'  => $job['remote'] ? trim( $job['location'] . ' ' . __( '(remote)', 'dev3-ai-wp-job-manager' ) ) : $job['location'],
			'salary'    => $job['salary'],
			'types'     => wp_list_pluck( $job['types'], 'name' ),
			'quality'   => (int) $job['quality'],
			'grade'     => Quality::grade( (int) $job['quality'] ),
			'issues'    => array_values( (array) $job['quality_issues'] ),
			'inclusive' => (int) $job['inclusive'],
			'date'      => mysql_to_rfc3339( $job['date'] ),
			'draft_id'  => $draft ? $draft['id'] : 0,
			'edit_url'  => $job['edit_url'],
			'view_url'  => $job['view_url'],
		);
	}

	/**
	 * GET /listings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listings( \WP_REST_Request $request ) {
		if ( ! post_type_exists( Wpjm::PT ) ) {
			return $this->respond(
				array(
					'items' => array(),
					'total' => 0,
				)
			);
		}
		$per     = min( 100, max( 1, (int) ( $request['per_page'] ? $request['per_page'] : 20 ) ) );
		$page    = max( 1, (int) ( $request['page'] ? $request['page'] : 1 ) );
		$status  = in_array( $request['status'], Wpjm::statuses(), true ) ? $request['status'] : array( 'publish', 'pending', 'draft', 'expired' );
		$orderby = in_array( $request['orderby'], array( 'quality', 'date', 'title' ), true ) ? $request['orderby'] : 'date';
		$order   = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
		$args    = array(
			'post_type'      => Wpjm::PT,
			'post_status'    => $status,
			'posts_per_page' => $per,
			'paged'          => $page,
			's'              => sanitize_text_field( (string) $request['search'] ),
			'order'          => $order,
		);
		if ( 'quality' === $orderby ) {
			$args['meta_key'] = '_dev3jm_quality'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value_num';
		} else {
			$args['orderby'] = $orderby;
		}
		$grade = (string) $request['grade'];
		if ( in_array( $grade, array( 'good', 'fair', 'poor' ), true ) ) {
			$ranges             = array(
				'good' => array( 80, 100 ),
				'fair' => array( 55, 79 ),
				'poor' => array( 0, 54 ),
			);
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_dev3jm_quality',
					'value'   => $ranges[ $grade ],
					'type'    => 'NUMERIC',
					'compare' => 'BETWEEN',
				),
			);
		}
		$q     = new \WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $post ) {
			$job = Wpjm::get( $post );
			if ( $job ) {
				$items[] = $this->row( $job );
			}
		}
		return $this->respond(
			array(
				'items' => $items,
				'total' => (int) $q->found_posts,
			)
		);
	}

	/**
	 * GET /listings/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listing( \WP_REST_Request $request ) {
		$job = $this->editable( $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		$quality = Quality::refresh( $job['id'] );
		$job     = Wpjm::get( $job['id'] );
		$schema  = Schema_Enhancer::for_listing( $job['id'] );
		return $this->respond(
			array(
				'listing' => $job,
				'quality' => $quality,
				'schema'  => $schema,
				'checks'  => is_array( $schema ) ? Schema_Enhancer::validate( $schema ) : array(),
				'draft'   => Drafts::pending_for( $job['id'] ),
				'salary'  => \Dev3\AiWpJobManager\Text\Salary::parse( $job['salary'], $job['salary_currency'], $job['salary_unit'] ),
			)
		);
	}

	/**
	 * POST /listings/<id>/apply: writes accepted fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply( \WP_REST_Request $request ) {
		$job = $this->editable( $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		$fields = (array) $request->get_param( 'fields' );
		$fields = array_intersect_key( $fields, array_flip( array( 'title', 'content', 'salary', 'seniority', 'types', 'categories', 'tags', 'fixes' ) ) );
		if ( ! $fields ) {
			return $this->respond( new \WP_Error( 'dev3jm_empty', __( 'Nothing to save.', 'dev3-ai-wp-job-manager' ), array( 'status' => 400 ) ) );
		}
		$res = Wpjm::apply( $job['id'], Drafts::to_fields( $fields, $job['id'] ) );
		if ( is_wp_error( $res ) ) {
			return $this->respond( $res );
		}
		return $this->listing( $request );
	}

	/**
	 * POST /ai/write.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_write( \WP_REST_Request $request ) {
		$job = $this->input_job( $request );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		$in  = $job['id'] ? Writer::input_from_listing( $job, (string) $request['notes'], (string) $request['tone'] ) : array(
			'title'    => $job['title'],
			'notes'    => (string) $request['notes'] . "\n" . Wpjm::plain( $job['content'], 4000 ),
			'company'  => $job['company'],
			'location' => $job['location'],
			'salary'   => $job['salary'],
			'types'    => (string) $request['types'],
			'remote'   => (bool) $request['remote'],
			'tone'     => (string) $request['tone'],
		);
		$res = Writer::draft( $in, 'admin', (bool) $request['template'] );
		return $this->respond( $res );
	}

	/**
	 * POST /ai/inclusive.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_inclusive( \WP_REST_Request $request ) {
		$job = $this->input_job( $request );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		return $this->respond( Inclusive::check( $job['content'], 'admin', false !== $request['use_ai'] ) );
	}

	/**
	 * POST /ai/normalise.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_normalise( \WP_REST_Request $request ) {
		$job = $this->input_job( $request );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		return $this->respond( Normaliser::run( $job, 'admin', false !== $request['use_ai'], (bool) $request['force'] ) );
	}

	/**
	 * POST /ai/suggest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_suggest( \WP_REST_Request $request ) {
		$job = $this->input_job( $request );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		return $this->respond( Suggester::run( $job, 'admin', false !== $request['use_ai'] ) );
	}

	/**
	 * POST /bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk_start( \WP_REST_Request $request ) {
		$active = Jobs::active( Improver::JOB );
		if ( $active ) {
			return $this->respond( new \WP_Error( 'dev3jm_busy', __( 'A bulk improve run is already in progress.', 'dev3-ai-wp-job-manager' ), array( 'status' => 409 ) ) );
		}
		$res = Improver::start( sanitize_key( (string) $request['filter'] ), array_map( 'intval', (array) $request['ids'] ), array_map( 'sanitize_key', (array) $request['ops'] ), false !== $request['use_ai'] );
		return $this->respond( $res, 201 );
	}

	/**
	 * GET /bulk/recent.
	 *
	 * @return \WP_REST_Response
	 */
	public function bulk_recent() {
		return $this->respond(
			array(
				'active' => Jobs::active( Improver::JOB ),
				'recent' => Jobs::recent( Improver::JOB, 10 ),
			)
		);
	}

	/**
	 * GET /drafts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function drafts( \WP_REST_Request $request ) {
		return $this->respond(
			Drafts::query(
				array(
					'status'   => (string) $request['status'],
					'batch_id' => (int) $request['batch_id'],
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
				)
			)
		);
	}

	/**
	 * POST /drafts/<id>/accept.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function draft_accept( \WP_REST_Request $request ) {
		$edited = $request->get_param( 'draft' );
		$res    = Drafts::accept( (int) $request['id'], is_array( $edited ) ? $edited : null );
		return $this->respond( is_wp_error( $res ) ? $res : array( 'listing' => $res ) );
	}

	/**
	 * POST /drafts/<id>/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function draft_reject( \WP_REST_Request $request ) {
		$res = Drafts::reject( (int) $request['id'] );
		return $this->respond( is_wp_error( $res ) ? $res : array( 'ok' => true ) );
	}

	/**
	 * POST /drafts/accept-safe.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function drafts_accept_safe( \WP_REST_Request $request ) {
		return $this->respond( Drafts::accept_safe( (int) $request['batch_id'] ) );
	}

	/**
	 * GET /matching: jobs to pick from + Resume Manager status.
	 *
	 * @return \WP_REST_Response
	 */
	public function matching_jobs() {
		$jobs = array();
		if ( post_type_exists( Wpjm::PT ) ) {
			foreach ( get_posts(
				array(
					'post_type'      => Wpjm::PT,
					'post_status'    => array( 'publish', 'pending', 'draft' ),
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
					'orderby'        => 'date',
				)
			) as $p ) {
				$jobs[] = array(
					'id'    => (int) $p->ID,
					'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				);
			}
		}
		return $this->respond(
			array(
				'active'  => Resumes::active(),
				'resumes' => Resumes::count(),
				'jobs'    => $jobs,
				'ai'      => (bool) \Dev3\AiWpJobManager\Settings::get( 'match_ai', true ) && Writer::ai_available( 'admin' ),
			)
		);
	}

	/**
	 * GET /matching/<id>: ranked resumes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function matching( \WP_REST_Request $request ) {
		$job = $this->editable( $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		if ( ! Resumes::active() ) {
			return $this->respond( new \WP_Error( 'dev3jm_no_resumes', __( 'Candidate matching needs the WP Job Manager Resume Manager add-on.', 'dev3-ai-wp-job-manager' ), array( 'status' => 400 ) ) );
		}
		return $this->respond(
			array(
				'job'   => array(
					'id'       => $job['id'],
					'title'    => $job['title'],
					'skills'   => array_values( Matcher::job_skills( $job ) ),
					'years'    => Matcher::required_years( $job ),
					'location' => $job['location'],
					'remote'   => $job['remote'],
				),
				'items' => Matcher::ranked( $job['id'] ),
			)
		);
	}

	/**
	 * POST /matching/<id>/score: scores up to 10 resumes now (AI explanation when on).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function matching_score( \WP_REST_Request $request ) {
		$job = $this->editable( $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		$ids = array_slice( array_filter( array_map( 'intval', (array) $request['resume_ids'] ) ), 0, 10 );
		if ( ! $ids ) {
			$ids = array_slice( Resumes::ids( 10 ), 0, 10 );
		}
		$out = array();
		$err = null;
		foreach ( $ids as $rid ) {
			$res = Matcher::match( $job['id'], $rid, false !== $request['use_ai'] );
			if ( is_wp_error( $res ) ) {
				$err = $res;
				break;
			}
			$out[] = $res;
		}
		if ( $err && ! $out ) {
			return $this->respond( $err );
		}
		return $this->respond(
			array(
				'scored' => count( $out ),
				'error'  => $err ? array(
					'code'    => $err->get_error_code(),
					'message' => $err->get_error_message(),
				) : null,
				'items'  => Matcher::ranked( $job['id'] ),
			)
		);
	}
}
