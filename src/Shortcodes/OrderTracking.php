<?php

namespace TrackMage\WordPress\Shortcodes;

use TrackMage\WordPress\Helper;

class OrderTracking extends \WC_Shortcode_Order_Tracking{

    /**
     * Output the shortcode.
     *
     * @param array $atts Shortcode attributes.
     */
    public static function output( $atts ) {
        // Check cart class is loaded or abort.
        if ( is_null( WC()->cart ) ) {
            return;
        }

        $atts        = shortcode_atts( array(), $atts, 'woocommerce_order_tracking' );
        $nonce_value = wc_get_var( $_REQUEST['woocommerce-order-tracking-nonce'], wc_get_var( $_REQUEST['_wpnonce'], '' ) ); // @codingStandardsIgnoreLine.

        if ( isset( $_REQUEST['orderid'] ) && wp_verify_nonce( $nonce_value, 'woocommerce-order_tracking' ) ) { // WPCS: input var ok.

            $order_id    = empty( $_REQUEST['orderid'] ) ? 0 : ltrim( wc_clean( wp_unslash( $_REQUEST['orderid'] ) ), '#' ); // WPCS: input var ok.
            $order_email = empty( $_REQUEST['order_email'] ) ? '' : sanitize_email( wp_unslash( $_REQUEST['order_email'] ) ); // WPCS: input var ok.

            if ( ! $order_id && ! $order_email) {
                wc_print_notice( __( 'Please enter a valid order ID or email address', 'trackmage' ), 'error' );
            } elseif (  $order_id && ! $order_email ) {
                wc_print_notice(__('Please enter a valid email address', 'woocommerce'), 'error');
            } elseif (!$order_id && $order_email) {
                $result = Helper::requestShipmentsInfoByEmail($order_email);
                if (null !== $result) {
                    wc_print_notice(__('We have sent your tracking link to your email (if it exists). Please check your email to see where your parcel is.', 'trackmage'));
                } else {
                    wc_print_notice(__('No information found for the email. Please try again with the correct data or contact us if you are having difficulty finding your order details.', 'trackmage'), 'error');
                }
            } else {
                $order = wc_get_order( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $order_id ) );

                if ( $order && $order->get_id() && strtolower( $order->get_billing_email() ) === strtolower( $order_email ) ) {
                    do_action( 'woocommerce_track_order', $order->get_id() );
                    wc_get_template(
                        'order/tracking.php',
                        array(
                            'order' => $order,
                        )
                    );
                    // TODO:: add shipment details for the founded order
                    return;
                } else {
                    wc_print_notice( __( 'Sorry, the order could not be found. Please contact us if you are having difficulty finding your order details.', 'woocommerce' ), 'error' );
                }
            }
        }

        ob_start();
        wc_get_template( 'order/form-tracking.php' );
        $out = ob_get_clean();
        preg_match('/<label\sfor="orderid">.*?<\/label>/', $out, $matches);
        $out = preg_replace('/<label\sfor="orderid">.*?<\/label>/', __('Order ID', 'woocommerce') . ' ('. __('optional', 'trackmage') . ')', $out);
        echo $out;
    }

}
