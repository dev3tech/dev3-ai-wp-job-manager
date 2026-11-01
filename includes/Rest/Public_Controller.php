<?php
/**
 * Public routes for the WPJM front-end submit form (employers are often logged out). They use
 * `public_permission` (same origin + per-IP rate limit), the `submission` AI context (honours the
 * public AI switch and monthly cap, no retries), cached prompts and a non-AI fallback. They only
 * transform the text sent to them and never read or write stored data.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Rest;

use Dev3\AiWpJobManager\Ai\Inclusive;
use Dev3\AiWpJobManager\Ai\Normaliser;
use Dev3\AiWpJobManager\Ai\Suggester;
use Dev3\AiWpJobManager\Ai\Writer;
use Dev3\AiWpJobManager\Core3\Rest\Base_Controller;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Text\Salary;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3jm/v1/public.
 */
class Public_Controller extends Base_Controller {

	const CONTEXT = 'submission';

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			'/public/write'     => array( 'write', 'submit_writer' ),
			'/public/inclusive' => array( 'inclusive', 'submit_inclusive' ),
			'/public/suggest'   => array( 'suggest', 'submit_suggest' ),
			'/public/salary'    => array( 'salary', 'salary_hint' ),
		);
		foreach ( $routes as $path => $r ) {
			$setting = $r[1];
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $r[0] ),
					'permission_callback' => function ( \WP_REST_Request $request ) use ( $setting ) {
						if ( ! Settings::get( $setting, true ) ) {
							return new \WP_Error( 'dev3jm_disabled', __( 'This feature is turned off.', 'dev3-ai-wp-job-manager' ), array( 'status' => 403 ) );
						}
						$bucket = 'salary_hint' === $setting ? 'dev3jm_public_salary' : 'dev3jm_public';
						$limit  = 'salary_hint' === $setting ? 60 : (int) Settings::get( 'public_limit', 10 );
						return $this->public_permission( $request, $bucket, $limit );
					},
				)
			);
		}
	}

	/**
	 * Clean listing input from the form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function form( \WP_REST_Request $request ) {
		$text = static function ( $key, $len ) use ( $request ) {
			return mb_substr( sanitize_text_field( (string) $request[ $key ] ), 0, $len );
		};
		return array(
			'title'           => $text( 'title', 200 ),
			'content'         => mb_substr( wp_kses_post( (string) $request['content'] ), 0, 20000 ),
			'company'         => $text( 'company', 120 ),
			'company_tagline' => $text( 'tagline', 160 ),
			'location'        => $text( 'location', 120 ),
			'remote'          => (bool) $request['remote'],
			'salary'          => $text( 'salary', 120 ),
			'salary_currency' => $text( 'salary_currency', 3 ),
			'salary_unit'     => $text( 'salary_unit', 5 ),
			'seniority'       => '',
			'types'           => array(),
			'categories'      => array(),
			'tags'            => array(),
		);
	}

	/**
	 * POST /public/write: AI draft, falling back to the template outline.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function write( \WP_REST_Request $request ) {
		$f   = $this->form( $request );
		$in  = array(
			'title'    => $f['title'],
			'notes'    => mb_substr( sanitize_textarea_field( (string) $request['notes'] ), 0, 3000 ) . "\n" . \Dev3\AiWpJobManager\Wpjm\Wpjm::plain( $f['content'], 3000 ),
			'company'  => $f['company'],
			'tagline'  => $f['company_tagline'],
			'location' => $f['location'],
			'remote'   => $f['remote'],
			'types'    => mb_substr( sanitize_text_field( (string) $request['types'] ), 0, 120 ),
			'salary'   => $f['salary'],
			'tone'     => (string) $request['tone'],
		);
		$res = Writer::draft( $in, self::CONTEXT );
		if ( is_wp_error( $res ) && 'dev3jm_writer_empty' !== $res->get_error_code() ) {
			$res = Writer::template( Writer::clean_input( $in ) );
		}
		if ( is_array( $res ) ) {
			unset( $res['meta'] );
		}
		return $this->respond( $res );
	}

	/**
	 * POST /public/inclusive.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function inclusive( \WP_REST_Request $request ) {
		$content = $this->form( $request )['content'];
		$res     = Inclusive::check( $content, self::CONTEXT, true );
		if ( is_wp_error( $res ) && 'dev3jm_inclusive_empty' !== $res->get_error_code() ) {
			$res = Inclusive::check( $content, self::CONTEXT, false );
		}
		if ( is_array( $res ) ) {
			unset( $res['meta'] );
		}
		return $this->respond( $res );
	}

	/**
	 * POST /public/suggest: job types and tags (fills the form's own selects).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function suggest( \WP_REST_Request $request ) {
		$res = Suggester::run( $this->form( $request ), self::CONTEXT, true );
		unset( $res['meta'], $res['error'] );
		return $this->respond( $res );
	}

	/**
	 * POST /public/salary: reading of the salary field (rules; AI only for odd inputs).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function salary( \WP_REST_Request $request ) {
		$f   = $this->form( $request );
		$res = Normaliser::run( $f, self::CONTEXT, (bool) $request['use_ai'] );
		return $this->respond(
			array(
				'salary'     => $res['salary'],
				'reading'    => $res['reading'],
				'negotiable' => $res['negotiable'],
				'guessed'    => $res['guessed'],
				'empty'      => '' === trim( $f['salary'] ),
				'currency'   => Settings::currency(),
				'units'      => array_keys( array_flip( Salary::UNITS ) ),
			)
		);
	}
}
