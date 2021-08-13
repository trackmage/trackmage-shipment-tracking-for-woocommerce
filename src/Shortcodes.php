<?php


namespace TrackMage\WordPress;


use WC_Shortcodes;

class Shortcodes {

	private static $shortcodes = array();

	/**
	 * Init shortcodes.
	 */
	public function init() {
		foreach ( self::$shortcodes as $shortcode => $function ) {
			add_shortcode( $shortcode, $function );
		}
        $this->overriderShortcodes();
	}

	/**
	 * Override woocommerce_track_order shortcode
	 *
	 * @return string
	 */
	public static function order_tracking() {
		return WC_Shortcodes::shortcode_wrapper( array( __NAMESPACE__.'\\Shortcodes\\OrderTracking', 'output' ), [] );
	}

    private function overriderShortcodes() {
        add_action( 'init', function(){
            remove_shortcode( 'woocommerce_order_tracking' );
            add_shortcode( 'woocommerce_order_tracking', __CLASS__.'::order_tracking');
        }, 11 );
    }

}
