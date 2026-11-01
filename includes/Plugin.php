<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager;

use Dev3\AiWpJobManager\Core3\Core\Assets;
use Dev3\AiWpJobManager\Core3\Core\Jobs;
use Dev3\AiWpJobManager\Core3\Core\Privacy;
use Dev3\AiWpJobManager\Core3\Core\Schema;
use Dev3\AiWpJobManager\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3jm_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets registered once per request.
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3JM_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3jm_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3jm_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3jm_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3jm_schema_sql', array( Tables::class, 'sql' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', static fn() => load_plugin_textdomain( 'dev3-ai-wp-job-manager', false, dirname( plugin_basename( DEV3JM_FILE ) ) . '/languages' ), 1 );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3JM_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		Privacy::init();
		add_action(
			'init',
			static function () {
				Privacy::add_exporter( __( 'Candidate match scores', 'dev3-ai-wp-job-manager' ), array( Privacy_Data::class, 'export' ) );
				Privacy::add_eraser( __( 'Candidate match scores', 'dev3-ai-wp-job-manager' ), array( Privacy_Data::class, 'erase' ) );
			}
		);
		// Background job types must be registered on every request (the runner looks them up).
		Jobs::register( Ai\Improver::JOB, array( Ai\Improver::class, 'run_chunk' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		Quality\Quality::init();
		Wpjm\Tags::init();
		Wpjm\Schema_Enhancer::init();
		Front\Submit_Form::init();
		add_action( 'before_delete_post', array( Privacy_Data::class, 'on_delete' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Registers admin bundles once (Assets::register would add the boot script again on every call).
	 */
	public static function register_assets() {
		if ( self::$assets || ! is_admin() ) {
			return;
		}
		self::$assets = true;
		Assets::register(
			array( 'admin', 'metabox' ),
			array(
				'wpjm'     => Wpjm\Wpjm::status(),
				'appUrl'   => Admin\Admin_Page::url(),
				'tones'    => Settings::TONES,
				'currency' => Settings::currency(),
				'canEdit'  => current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Rest_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}
