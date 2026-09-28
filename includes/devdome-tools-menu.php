<?php
/**
 * DevDome Tools — shared parent-menu bootstrap + Overview landing.
 *
 * Bundled IDENTICALLY in every DevDome plugin. Whichever DevDome plugin loads
 * first registers the single "DevDome Tools" top-level menu + the Overview page;
 * the others detect it and just attach their screens as submenus (Analytics = 1,
 * Product Importer = 2, …). So the menu + Overview appear if ANY DevDome plugin
 * is active and work with any subset.
 *
 * The Overview delegates to the bundled devdome-core hub (this plugin always
 * vendors + loads it before the menu registers, so the hub is always present).
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DEVDCOREV1_TOOLS_MENU_SLUG' ) ) {
	define( 'DEVDCOREV1_TOOLS_MENU_SLUG', 'devdcorev1-tools' );

	/** Register the shared top-level menu + Overview once (guarded against duplicates). */
	function devdcorev1_tools_register_parent_menu() {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks[ DEVDCOREV1_TOOLS_MENU_SLUG ] ) ) {
			return;
		}
		add_menu_page(
			'DevDome',
			'DevDome',
			'manage_options',
			DEVDCOREV1_TOOLS_MENU_SLUG,
			'devdcorev1_tools_render_overview',
			// Icon is painted by devdcorev1_tools_menu_icon_css() below: a base64 icon here gets
			// repainted to a solid box by WP's svg-painter.js, and a plain data: URI is blanked
			// by esc_url() in menu-header.php — a CSS background dodges both.
			'none',
			57 // below the site-content group, above Appearance (60)
		);
		// Explicit first submenu = "Overview" (position 0), pointing at the parent page.
		add_submenu_page(
			DEVDCOREV1_TOOLS_MENU_SLUG,
			'DevDome',
			'Dashboard',
			'manage_options',
			DEVDCOREV1_TOOLS_MENU_SLUG,
			'devdcorev1_tools_render_overview',
			0
		);
	}
	add_action( 'admin_menu', 'devdcorev1_tools_register_parent_menu', 9 );

	/**
	 * The menu icon: the site favicon (blue rounded square, white vector "DD") as a CSS
	 * background. Full color, crisp at 20px, and immune to svg-painter recoloring.
	 */
	function devdcorev1_tools_menu_icon_css() {
		$svg = rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#2563eb"/><path fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" d="M7 11.5V20.5M7 11.5h1a6.25 4.5 0 0 1 0 9H7M17.75 11.5V20.5M17.75 11.5h1a6.25 4.5 0 0 1 0 9h-1"/></svg>' );
		wp_add_inline_style( 'admin-menu', '#adminmenu .toplevel_page_' . DEVDCOREV1_TOOLS_MENU_SLUG . ' .wp-menu-image{background:url("data:image/svg+xml,' . $svg . '") no-repeat center/20px 20px;}' );
	}
	add_action( 'admin_enqueue_scripts', 'devdcorev1_tools_menu_icon_css' );

	/** The suite Overview page — delegates to the devdome-core hub Dashboard. */
	function devdcorev1_tools_render_overview() {
		if ( function_exists( 'devdcorev1_hub_render_overview' ) ) {
			devdcorev1_hub_render_overview();
			return;
		}
		// Unreachable in practice (the vendored core always loads first); minimal fallback.
		echo '<div class="wrap"><h1>DevDome</h1><p>' . esc_html__( 'The DevDome suite dashboard could not be loaded.', 'devdome-country-blocker' ) . '</p></div>';
	}
}
