<?php
/**
 * TrackMage for WordPress
 *
 * Easily integrate TrackMage with WordPress.
 *
 * @package TrackMage\WordPress
 * @author  TrackMage
 *
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       TrackMage for WordPress
 * Plugin URI:        https://github.com/trackmage/trackmage-wordpress-plugin
 * Description:       Easily integrate TrackMage with WordPress.
 * Version:           0.1.0
 * Author:            TrackMage
 * Author URI:        https://trackmage.com
 * Text Domain:       trackmage
 * License:           GPL-2.0-or-later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI: https://github.com/trackmage/trackmage-wordpress-plugin
 * Requires PHP:      5.6
 * Requires WP:       4.7
 */

use BrightNucleus\Config\ConfigFactory;
use TrackMage\WordPress\Plugin;
use TrackMage\WordPress\Helper;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once ABSPATH . '/wp-admin/includes/plugin.php';

if (PHP_VERSION_ID < 50600 || (!is_plugin_active('woocommerce/woocommerce.php') && !is_plugin_active_for_network('woocommerce/woocommerce.php'))) {
	add_action( 'plugins_loaded', 'trackmage_init_deactivation' );

	/**
	 * Initialise deactivation functions.
	 */
	function trackmage_init_deactivation() {
		if ( current_user_can( 'activate_plugins' ) ) {
			add_action( 'admin_init', 'trackmage_deactivate' );
			add_action( 'admin_notices', 'trackmage_deactivation_notice' );
		}
	}

	/**
	 * Deactivate the plugin.
	 */
	function trackmage_deactivate() {
		deactivate_plugins( plugin_basename( __FILE__ ) );
        Helper::clearTransients();
	}

	/**
	 * Show deactivation admin notice.
	 */
	function trackmage_deactivation_notice() {
		$notice = sprintf(
			// Translators: 1: Required PHP version, 2: Current PHP version.
			__( '<strong>TrackMage for WordPress</strong> requires PHP %1$s to run. This site uses %2$s, so the plugin has been <strong>deactivated</strong>.', 'trackmage' ),
			'5.6',
			PHP_VERSION
		);
		?>
		<div class="updated"><p><?php echo wp_kses_post( $notice ); ?></p></div>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}

	return false;
}



if ( ! defined( 'TRACKMAGE_VERSION' ) ) {
    // phpcs:ignore NeutronStandard.Constants.DisallowDefine.Define
    define( 'TRACKMAGE_VERSION', '1.0.0' );
}

if ( ! defined( 'TRACKMAGE_DIR' ) ) {
    // phpcs:ignore NeutronStandard.Constants.DisallowDefine.Define
    define( 'TRACKMAGE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'TRACKMAGE_VIEWS_DIR' ) ) {
    // phpcs:ignore NeutronStandard.Constants.DisallowDefine.Define
    define( 'TRACKMAGE_VIEWS_DIR', plugin_dir_path( __FILE__ ) . 'views/' );
}

if ( ! defined( 'TRACKMAGE_URL' ) ) {
    // phpcs:ignore NeutronStandard.Constants.DisallowDefine.Define
    define( 'TRACKMAGE_URL', plugin_dir_url( __FILE__ ) );
}

// Load Composer autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}


define('TRACKMAGE_PLUGIN_FILE',	__FILE__);

if (!defined('TRACKMAGE_API_DOMAIN')) {
    define('TRACKMAGE_API_DOMAIN', 'https://api.trackmage.com');
}

if (!defined('TRACKMAGE_APP_DOMAIN')) {
    define('TRACKMAGE_APP_DOMAIN', 'https://app.trackmage.com');
}

add_action('plugins_loaded', 'trackMageInit');
register_activation_hook(__FILE__, 'trackMageActivate');
register_deactivation_hook(__FILE__, 'trackMageDeactivate');
//register_uninstall_hook( __FILE__, 'trackMageUninstall');

/**
 * trackMageActivate
 *
 * Plugin activate event
 */
function trackMageActivate() {
    $plugin = Plugin::instance();
    $plugin->init(ConfigFactory::create( __DIR__ . '/config/defaults.php' )->getSubConfig( 'TrackMage\WordPress' ));
    $plugin->getInstanceId();
    foreach($plugin->getRepos() as $repository) {
        try {
            $repository->init();
            $plugin->getLogger()->info("Successfully created table {$repository->getTable()}");
        } catch(Exception $e) {
            $plugin->getLogger()->critical("Unable to create table {$repository->getTable()}: {$e->getMessage()}");
        }
    }
    if(!get_transient('trackmage-wizard-notice'))
        set_transient( 'trackmage-wizard-notice', true );
}

/**
 * Plugin deactivate event
 */
function trackMageDeactivate() {
    Helper::clearTransients();
}


/**
 * Initialize trackMage plugin components
 */
function trackMageInit() {
    if(!is_plugin_active('woocommerce/woocommerce.php') && !is_plugin_active_for_network('woocommerce/woocommerce.php')) {
        add_action('admin_notices', 'trackMageWooCommerceError');
        return;
    }
    Plugin::instance()->init(ConfigFactory::create( __DIR__ . '/config/defaults.php' )->getSubConfig( 'TrackMage\WordPress' ));
}


/**
 * Display error message: WooCommerce not active
 */
function trackMageWooCommerceError() {
    printf('<div class="error"><p>%s</p></div>', __('To use TrackMage for WooCommerce it is required that WooCommerce is installed and activated'));
}

function trackMageUninstall(){
    foreach(Plugin::instance()->getRepos() as $repository) {
        try {
            $repository->drop();
        } catch(Exception $e) {
            Plugin::instance()->getLogger()->critical("Unable to drop table {$repository->getTable()}: {$e->getMessage()}");
        }
    }
    Helper::clearTransients();
}
