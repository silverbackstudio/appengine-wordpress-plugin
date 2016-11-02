<?php
/**
 * Core functionality to get WordPress working on App Engine
 *
 * Copyright 2013 Google Inc.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @package WordPress
 * @subpackage Mail
 */

namespace google\appengine\WordPress;

Core::bootstrap();

/**
 * Core functionality for WordPress on App Engine
 *
 * This includes setting some sensible defaults (e.g. rewrites, SSL admin, file
 * editing), as well as infrastructure for the other modules.
 *
 * @package WordPress
 * @subpackage Mail
 */
class Core {
	/**
	 * Normal priority for filters
	 */
	const NORMAL_PRIORITY = 10;

	/**
	 * Low priority for filters
	 */
	const LOW_PRIORITY = 100;

	/**
	 * When was the admin.css file last updated?
	 */
	const CSS_VERSION = '201305100635';

	/**
	 * Set required settings and register our actions
	 */
	public static function bootstrap() {
		global $PHP_SELF;
		$_SERVER['PHP_SELF'] = $PHP_SELF = preg_replace( '/(\?.*)?$/', '', $_SERVER['REQUEST_URI'] );

		add_filter( 'got_rewrite', '__return_true', self::LOW_PRIORITY );
		if( is_production() ) {
			add_filter( 'secure_auth_redirect', '__return_true' );
			force_ssl_admin( true );
			
			defined( 'DISALLOW_FILE_EDIT' ) or define( 'DISALLOW_FILE_EDIT', true );
			defined( 'DISALLOW_FILE_MODS' ) or define( 'DISALLOW_FILE_MODS', true );
		}
		defined( 'DISABLE_WP_CRON' ) or define( 'DISABLE_WP_CRON', true );

    // We don't want to use fsockopen as on App Engine it's not efficient
    add_filter( 'use_fsockopen_transport', '__return_false' );

		// ::settings_link() takes 2 parameters
		add_filter( 'plugin_action_links', __CLASS__ . '::settings_link', self::NORMAL_PRIORITY, 2 );
		add_filter( 'wpseo_canonical', __CLASS__ . '::force_canonical_domain' );
		add_filter( 'robots_txt', __CLASS__ . '::noindex_appspot_subdomains' );
		add_action( 'appengine_register_settings', __CLASS__ . '::register_google_settings' );
		add_action( 'admin_enqueue_scripts', __CLASS__ . '::register_styles' );
		add_action( 'admin_menu', __CLASS__ . '::register_settings_page' );
		add_action( 'admin_init', __CLASS__ . '::register_settings' );
		add_action( 'init', __CLASS__ . '::load_textdomain' );

	}

	public static function noindex_appspot_subdomains($output){
		
		$canonical_url = get_option('appengine_canonical_domain', '');

		if ($canonical_url && get_option('appengine_noindex_versions', false) && (HTTP_HOST != parse_url($canonical_url, PHP_URL_HOST)) ){
			    $output = "User-agent: *\n";
				$output .= "Disallow: /\n";
		}
		
		return $output;
	}

	public static function force_canonical_domain($canonical){
		
		$canonical_domain = get_option('appengine_canonical_domain', '');
		
		if(!empty($canonical_domain)){

			$url_bits = parse_url($canonical);
			
			$canonical = $canonical_domain;
			$canonical .= isset($url_bits['path']) ? $url_bits['path'] : '';
			$canonical .= isset($url_bits['query']) ? '?'.$url_bits['query'] : '';
			$canonical .= isset($url_bits['fragment']) ? '#' . $url_bits['fragment'] : '';
			
		}
		
		return $canonical;
	}

	/**
	 * Load the translation text domain for App Engine
	 *
	 * @wp-action init
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'appengine', false, dirname( plugin_basename( PLUGIN_PATH ) ) . '/languages/' );
	}

	/**
	 * Add a settings link to the plugin
	 *
	 * @wp-action plugin_action_links
	 */
	public static function settings_link( $links, $file ) {
		if ( $file == plugin_basename( PLUGIN_PATH ) ) {
			$links[] = '<a href="' . admin_url( 'options-general.php?page=appengine' ) . '">'
				. __( 'Settings', 'appengine' ) . '</a>';
		}

		return $links;
	}

	/**
	 * Register the App Engine settings page
	 *
	 * @wp-action admin_menu
	 */
	public static function register_settings_page() {
		add_options_page(
			__( 'App Engine Options', 'appengine' ),
			__( 'App Engine', 'appengine' ),
			'manage_options',
			'appengine',
			__CLASS__ . '::settings_view'
		);
	}

	public static function register_google_settings() {
		
    	register_setting('appengine_settings', 'appengine_canonical_domain', __CLASS__ . '::canonical_domain_validation');
    	register_setting('appengine_settings', 'appengine_force_ssl_frontend', __CLASS__ . '::frontend_ssl_validation');
    	register_setting('appengine_settings', 'appengine_noindex_versions', __CLASS__ . '::noindex_version_validation');

		add_settings_section('appengine-domain', __( 'Domain Settings', 'appengine' ), __CLASS__ . '::section_text', 'appengine');

		add_settings_field('appengine_canonical_domain',
                       __( 'Website Canonical Domain', 'appengine' ),
                       __CLASS__ . '::canonical_domain_input',
                       'appengine',
                       'appengine-domain',
                       ['label_for' => 'appengine_canonical_domain']);

		add_settings_field('appengine_force_ssl_frontend',
                       __('Force SSL in Frontend', 'appengine'),
                       __CLASS__ . '::force_frontend_ssl_input',
                       'appengine',
                       'appengine-domain',
                       ['label_for' => 'appengine_force_ssl_frontend']);

		add_settings_field('appengine_noindex_versions',
                       __('Index canonical domain only', 'appengine'),
                       __CLASS__ . '::appengine_noindex_versions',
                       'appengine',
                       'appengine-domain',
                       ['label_for' => 'appengine_noindex_versions']);		
                       
	}

	public static function canonical_domain_validation($input) {
		
		if(empty($input)){
			return '';
		}
		
		if (filter_var($input, FILTER_VALIDATE_URL) === FALSE) {
			add_settings_error( 'appengine_settings', 'invalid-canonical-domain', __( 'You have entered an invalid URL in the canonical domain', 'appengine' ) );
		}		
		
    	return untrailingslashit(esc_url_raw($input));
	}
	
	public static function frontend_ssl_validation($input) {
    	return (bool) $input;
	}
	
	public static function noindex_version_validation($input) {
		
		if( !get_option('appengine_canonical_domain', '') ){
			add_settings_error( 'appengine_settings', 'noinde-no-canonical-domain-', __( 'You must enter a canonical domain to use this feature', 'appengine' ) );
		}		
		
    	return (bool) $input;
	}
	
	public static function canonical_domain_input() {
		$canonical_domain = get_option( 'appengine_canonical_domain', '' );
		echo '<input id="appengine_canonical_domain" name="appengine_canonical_domain" type="text" value="' . esc_attr( $canonical_domain ) . '" />';
		echo '<p class="description">' . __( 'Leave blank to disable rewrite, requires Yoast SEO to work', 'appengine' ) . '</p>';
	}
	
	public static function force_frontend_ssl_input() {
		$enabled = get_option( 'appengine_force_ssl_frontend', '' );
		echo '<input id="appengine_force_ssl_frontend" name="appengine_force_ssl_frontend" type="checkbox" ' . checked( $enabled, true, false ) . ' />';
		echo '<p class="description">' . __( 'Redirect all frontend URLs to HTTPs. Please use HSTS insted of this.', 'appengine').'</p>';
	}	
	
	public static function appengine_noindex_versions() {
		$enabled = get_option( 'appengine_noindex_versions', false );
		echo '<input id="appengine_noindex_versions" name="appengine_noindex_versions" type="checkbox" ' . checked( $enabled, true, false ) . ' />';
		echo '<p class="description">' . __( 'Disable Search Engine Indexing on AppEngine Service domains (*.appspot.com) via robots.txt', 'appengine').'</p>';
	}		

	/**
	 * Register the styles for the App Engine administration UI
	 *
	 * @wp-action admin_enqueue_scripts
	 */
	public static function register_styles() {
		wp_enqueue_style( 'appengine-admin', plugins_url( 'static/admin.css', PLUGIN_PATH ), array(), self::CSS_VERSION, 'all' );
	}

	/**
	 * Display the App Engine options page
	 *
	 * This is registered in {@see self::register_settings_page()}
	 */
	public static function settings_view() {
?>
		<div class="wrap">
			<?php screen_icon( 'appengine' ); ?>
			<h2><?php _e( 'Google App Engine Options', 'appengine' ) ?></h2>
			<form action="options.php" method="POST">
				<?php
					settings_fields( 'appengine_settings' );
					do_settings_sections( 'appengine' );
					submit_button();
				?>
			</form>
		</div>
<?php
	}

	/**
	 * Register the App Engine settings
	 *
	 * This is a better hook for the modules to use, as it's much more
	 * descriptive, plus using it ensures that the Core module has loaded.
	 *
	 * @wp-action admin_init
	 */
	public static function register_settings() {
		do_action( 'appengine_register_settings' );
	}
}
