<?php
/**
 * Admin menu + React app mount, WPJM list column, edit-screen meta box and notices.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Admin;

use Dev3\AiWpJobManager\Core3\Core\Assets;
use Dev3\AiWpJobManager\Core3\Core\Capabilities;
use Dev3\AiWpJobManager\Quality\Quality;
use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Wpjm\Wpjm;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page and WPJM screen integrations.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3JM_FILE ), array( $this, 'links' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'add_meta_boxes_' . Wpjm::PT, array( $this, 'meta_box' ) );
		add_filter( 'manage_edit-' . Wpjm::PT . '_columns', array( $this, 'columns' ), 20 );
		add_action( 'manage_' . Wpjm::PT . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . Wpjm::PT . '_sortable_columns', array( $this, 'sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_query' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
	}

	/**
	 * App URL (optionally a hash route).
	 *
	 * @param string $route Route.
	 * @return string
	 */
	public static function url( $route = '' ) {
		return admin_url( 'admin.php?page=' . DEV3JM_SLUG ) . ( '' !== $route ? '#/' . ltrim( $route, '/' ) : '' );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3JM_NAME, __( 'DEV3 AI Jobs', 'dev3-ai-wp-job-manager' ), Capabilities::manage(), DEV3JM_SLUG, array( $this, 'render' ), 'dashicons-superhero', 32 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3jm-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3JM_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3jm-admin-root" class="dev3jm-root dev3jm-root--app"></div></div>';
	}

	/**
	 * Assets on our page and the job edit screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && Wpjm::PT === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && Capabilities::can_generate() ) {
			Assets::enqueue( 'metabox' );
		}
		if ( $screen && 'edit-' . Wpjm::PT === $screen->id ) {
			wp_add_inline_style( 'list-tables', '.column-dev3jm_quality{width:92px}.dev3jm-q{display:inline-block;min-width:34px;padding:2px 8px;border-radius:999px;font-weight:600;text-align:center;font-size:12px;line-height:18px}.dev3jm-q--good{background:#dcfce7;color:#14532d}.dev3jm-q--fair{background:#fef3c7;color:#713f12}.dev3jm-q--poor{background:#fee2e2;color:#7f1d1d}' );
		}
	}

	/**
	 * Plugin row links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'dev3-ai-wp-job-manager' ) . '</a>' );
		return $links;
	}

	/**
	 * WPJM missing notice (plugins screen and dashboard only).
	 */
	public function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( Wpjm::active() || ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) || ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html( DEV3JM_NAME ),
			esc_html__( 'works with the free WP Job Manager plugin, which is not active.', 'dev3-ai-wp-job-manager' ),
			esc_url( admin_url( 'plugin-install.php?s=wp-job-manager&tab=search&type=term' ) ),
			esc_html__( 'Install WP Job Manager', 'dev3-ai-wp-job-manager' )
		);
	}

	/**
	 * Edit-screen meta box.
	 */
	public function meta_box() {
		add_meta_box( 'dev3jm-assistant', __( 'DEV3 AI Job Assistant', 'dev3-ai-wp-job-manager' ), array( $this, 'meta_box_render' ), Wpjm::PT, 'side', 'high' );
	}

	/**
	 * Meta box mount.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function meta_box_render( $post ) {
		printf(
			'<div id="dev3jm-metabox-root" class="dev3jm-root dev3jm-root--metabox" data-post="%1$d" data-app="%2$s"><p>%3$s</p></div>',
			(int) $post->ID,
			esc_url( self::url( 'listings/' . (int) $post->ID ) ),
			esc_html__( 'Loading…', 'dev3-ai-wp-job-manager' )
		);
	}

	/**
	 * Quality column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function columns( $cols ) {
		if ( ! Settings::get( 'quality_column', true ) ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'job_position' === $k || 'title' === $k ) {
				$out['dev3jm_quality'] = esc_html__( 'Quality', 'dev3-ai-wp-job-manager' );
			}
		}
		if ( ! isset( $out['dev3jm_quality'] ) ) {
			$out['dev3jm_quality'] = esc_html__( 'Quality', 'dev3-ai-wp-job-manager' );
		}
		return $out;
	}

	/**
	 * Column value.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public function column( $col, $id ) {
		if ( 'dev3jm_quality' !== $col ) {
			return;
		}
		$q = get_post_meta( $id, '_dev3jm_quality', true );
		if ( '' === $q ) {
			$res = Quality::refresh( $id );
			$q   = $res ? $res['score'] : 0;
		}
		$q = (int) $q;
		/* translators: %d: score */
		printf( '<a class="dev3jm-q dev3jm-q--%1$s" href="%2$s" title="%3$s">%4$d</a>', esc_attr( Quality::grade( $q ) ), esc_url( self::url( 'listings/' . (int) $id ) ), esc_attr( sprintf( __( 'Listing quality %d/100: open in DEV3 AI Job Assistant', 'dev3-ai-wp-job-manager' ), $q ) ), (int) $q );
	}

	/**
	 * Sortable column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function sortable( $cols ) {
		$cols['dev3jm_quality'] = 'dev3jm_quality';
		return $cols;
	}

	/**
	 * Sort by quality meta.
	 *
	 * @param \WP_Query $q Query.
	 */
	public function sort_query( $q ) {
		if ( is_admin() && $q->is_main_query() && 'dev3jm_quality' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', '_dev3jm_quality' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$q->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Row action.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( Wpjm::PT === $post->post_type && current_user_can( 'edit_post', $post->ID ) && current_user_can( Capabilities::manage() ) ) {
			$actions['dev3jm'] = '<a href="' . esc_url( self::url( 'listings/' . (int) $post->ID ) ) . '">' . esc_html__( 'Improve with DEV3 AI', 'dev3-ai-wp-job-manager' ) . '</a>';
		}
		return $actions;
	}
}
