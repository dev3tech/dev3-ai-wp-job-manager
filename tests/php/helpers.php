<?php
/**
 * Shared test helpers: HTTP mock, Gemini responses, base test case.
 *
 * @package Dev3\AiWpJobManager
 */

/**
 * Routes outgoing HTTP to test handlers (pattern => callable|array), refuses everything else.
 */
class Dev3jm_Http {

	/**
	 * Handlers.
	 *
	 * @var array
	 */
	public static $routes = array();

	/**
	 * Recorded requests.
	 *
	 * @var array
	 */
	public static $requests = array();

	/**
	 * Resets.
	 */
	public static function reset() {
		self::$routes   = array();
		self::$requests = array();
	}

	/**
	 * Registers a handler; a handler returns [ status, body(string|array) ].
	 *
	 * @param string         $pattern Substring of the URL.
	 * @param callable|array $handler Handler.
	 */
	public static function on( $pattern, $handler ) {
		self::$routes[ $pattern ] = $handler;
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed  $pre  Earlier result.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public static function handle( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		foreach ( self::$routes as $pattern => $handler ) {
			if ( false !== strpos( $url, $pattern ) ) {
				$body             = isset( $args['body'] ) ? $args['body'] : '';
				self::$requests[] = array(
					'url'  => $url,
					'args' => $args,
					'json' => is_string( $body ) ? json_decode( $body, true ) : $body,
					'raw'  => is_string( $body ) ? $body : wp_json_encode( $body ),
				);
				$res              = is_callable( $handler ) ? call_user_func( $handler, end( self::$requests ) ) : $handler;
				return array(
					'headers'  => array(),
					'body'     => is_string( $res[1] ) ? $res[1] : wp_json_encode( $res[1] ),
					'response' => array(
						'code'    => $res[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'Blocked in tests: ' . $url );
	}

	/**
	 * Gemini JSON response.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 120,
					'candidatesTokenCount' => 60,
					'totalTokenCount'      => 180,
				),
			),
		);
	}
}
