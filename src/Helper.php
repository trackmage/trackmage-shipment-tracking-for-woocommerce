<?php
/**
 * Utilities and helper functions.
 *
 * @package TrackMage\WordPress
 * @author  TrackMage
 */

namespace TrackMage\WordPress;

use GuzzleHttp\Exception\ClientException;
use TrackMage\Client\TrackMageClient;
use TrackMage\WordPress\Exception\RuntimeException;

/**
 * Static functions that can be called without instantiation.
 *
 * @since   0.1.0
 *
 * @package TrackMage\WordPress
 * @author  TrackMage
 */
class Helper {
    const CREDENTIALS_INVALID = 0;
    const CREDENTIALS_VALID = 1;
    const CREDENTIALS_ERROR = 2;

    /**
     * Check the validity of API credentials
     *
     * @param string|false $client_id     Client ID (default: false).
     * @param string $client_secret Client secret (default: false).
     *
     * @return int 0 if invalid, 1 if valid or 2 otherwise.
     */
    public static function check_credentials( $client_id = false, $client_secret = false ) {
        $client_id = ($client_id !== false) ? trim($client_id) : get_option( 'trackmage_client_id', '' );
        $client_secret = ($client_secret !== false) ? trim($client_secret) : get_option( 'trackmage_client_secret', '' );

        if(empty( $client_id ) || empty( $client_secret )){
            return self::CREDENTIALS_INVALID;
        }

        try {
            $client = new TrackMageClient( $client_id, $client_secret );
            $client->setHost(TRACKMAGE_API_DOMAIN);
            $client->get('/workspaces');
            return self::CREDENTIALS_VALID;
        } catch( ClientException $e ) {
            error_log('Unable to check credentials: '.TrackMageClient::error($e));
            if ($e->getResponse()->getStatusCode() === 401) {
                return self::CREDENTIALS_INVALID;
            }
        }
        return self::CREDENTIALS_ERROR;
    }

    /**
     * Returns a list of the workspaces created by the current user.
     *
     * @param bool $refresh
     * @return array|false Of workspaces, or an empty array if no workspaces found.
     * @since 0.1.0
     */
    public static function get_workspaces($refresh = false) {
        $workspaces = get_transient( 'trackmage_workspaces' );
        if ( false === $workspaces || $refresh) {
            try {
                $client   = Plugin::get_client();
                $response = $client->get( '/workspaces' );
                $result = TrackMageClient::collection($response);
                $workspaces = [];

                foreach ( $result as $workspace ) {
                    $workspaces[] = [
                        'id'    => $workspace['id'],
                        'title' => $workspace['title'],
                        'team'  => $workspace['team']
                    ];
                }
                set_transient( 'trackmage_workspaces', $workspaces, 3600 );
            } catch ( ClientException $e ) {
                error_log('Unable to fetch workspaces: '.TrackMageClient::error($e));
                return false;
            }
        }
        return $workspaces;
    }

    /**
     * Returns carriers.
     *
     * @since 0.1.0
     * @return array List of carriers.
     */
    public static function get_shipment_carriers() {
        $carriers = get_transient('trackmage_carriers');

        if ( false === $carriers ) {
            try {
                $client = Plugin::get_client();
                $response = $client->get('/public/carriers');
                $result = TrackMageClient::collection($response);

                $carriers = [];
                foreach ( $result as $carrier ) {
                    $carriers[] = [
                        'code' => $carrier['code'],
                        'name' => $carrier['name'],
                    ];
                }
                set_transient( 'trackmage_carriers', $carriers, 0 );
            } catch(ClientException $e) {
                error_log('Unable to fetch carriers: '.TrackMageClient::error($e));
                $carriers = [];
            }
        }

        return $carriers;
    }

    /**
     * Returns the available aliases.
     *
     * @since 0.1.0
     * @return array List of aliases.
     */
    public static function get_aliases() {
        $workspaceId = get_option( 'trackmage_workspace', 0 );
        $cachedAliases = get_transient('trackmage_order_statuses');
        $aliases = [];
        if($cachedAliases && isset($cachedAliases[$workspaceId])) {
            $aliases = $cachedAliases[$workspaceId];
        } elseif (!empty($workspaceId) && self::check_credentials() === self::CREDENTIALS_VALID){
            try {
                $client = Plugin::get_client();
                $response = $client->get("/workspaces/{$workspaceId}/statuses?entity=orders");
                $statuses = TrackMageClient::collection($response);
                $aliases = array_column($statuses, 'title', 'code');
                $cachedAliases[$workspaceId] = $aliases;
                set_transient('trackmage_order_statuses', $cachedAliases, 3600);
            } catch( ClientException $e ) {
                error_log('Unable to fetch statuses: '.TrackMageClient::error($e));
            }
        }
        return $aliases;
    }

    /**
     * Returns the used aliases.
     *
     * @return array List of aliases.
     */

    public static function get_used_aliases() {
        $usedAliases = get_option( 'trackmage_order_status_aliases', [] );
        return array_values(array_filter($usedAliases));
    }

    /**
     * @param \WC_Order $order
     * @return string|null
     */
    public static function getOrderTrackingPageLink($order)
    {
        $order_id = $order->get_id();
        $link = get_post_meta( $order_id, '_trackmage_tracking_page_link', true );
        if (!is_string($link) || $link === '') {
            $email = $order->get_billing_email();
            $link = Helper::getTrackingPageLink(['email' => $email]);
            add_post_meta($order_id, '_trackmage_tracking_page_link', $link, true)
                || update_post_meta($order_id, '_trackmage_tracking_page_link', $link);
        }
        return $link;
    }

    /**
     * @return string|null
     */
    public static function getTrackingPageLink( array $filter) {
        if(empty($filter)) {
            return null;
        }
        $client = Plugin::get_client();
        $workspaceId = get_option('trackmage_workspace');

        try {
            $response = $client->get( '/workspaces/' . $workspaceId );
            $data = TrackMageClient::item($response);
            $trackingPageId = isset($data['defaultTrackingPage']) ? explode('/tracking_pages/', $data['defaultTrackingPage'])[1] : null;
            if ($trackingPageId === null || $trackingPageId === '') {
                error_log(sprintf('defaultTrackingPage is empty for workspace %s', $workspaceId));
                return null;
            }
            $response = $client->post( '/generate_tracking_page_link', ['json' => [
                'trackingPageId' => $trackingPageId,
                'filter' => $filter,
            ]]);
            $data = TrackMageClient::item($response);
            return isset($data['link']) ? $data['link']: null;
        } catch( ClientException $e ) {
            error_log('Error in getTrackingPageLink: ', TrackMageClient::error($e));
        }
        return null;
    }

    /**
     * @return string|null
     */
    public static function requestShipmentsInfoByEmail( string $email) {
        if(empty($email)) {
            return null;
        }
        $client = Plugin::get_client();
        $workspaceId = get_option('trackmage_workspace');

        try {
            $response = $client->get( '/workspaces/' . $workspaceId );
            $data = TrackMageClient::item($response);
            $trackingPageId = isset($data['defaultTrackingPage']) ? $data['defaultTrackingPage'] : null;
            if ($trackingPageId === null || $trackingPageId === '') {
                error_log(sprintf('defaultTrackingPage is empty for workspace %s', $workspaceId), 0);
                return null;
            }
            $response = $client->get($trackingPageId);
            $trackingPage = TrackMageClient::item($response);
            $subdomain = isset($trackingPage['subdomain']) ? $trackingPage['subdomain'] : null;
            if ($subdomain === null || $subdomain === '') {
                error_log(sprintf('subdomain is empty for tracking page %s', $trackingPageId), 0);
                return null;
            }
            $response = $client->get( "/public/tracking_page_by_domain/{$subdomain}/link_search/{$email}");
            $data = TrackMageClient::item($response);
            return isset($data['text']) ? $data['text']: null;
        } catch( ClientException $e ) {
            error_log('Error in getTrackingPageLink: '. TrackMageClient::error($e), 0);
        }
        return null;
    }

    /**
     * @param int $orderId
     * @return array of shipments
     */
    public static function getOrderShipmentsWithJoinedItems($orderId)
    {
        $trackmageOrderId = get_post_meta( $orderId, '_trackmage_order_id', true );
        if (empty($trackmageOrderId))
            return [];

        $shipmentRepo = Plugin::instance()->getShipmentRepo();
        $shipmentItemRepo = Plugin::instance()->getShipmentItemsRepo();
        $order = wc_get_order($orderId);
        $orderItems = $order->get_items();
        $shipments = $shipmentRepo->findBy(['orderNumbers' => $order->get_order_number()]);
        $shipmentItems = $shipmentItemRepo->findBy(['orderNumber.id' => $trackmageOrderId]);
        foreach ($shipments as &$shipment) {
            $items = array_filter($shipmentItems, function($shipmentItem) use ($shipment) {
                return $shipmentItem['shipment'] === $shipment['@id'];
            });
            $shipment['items'] = self::mapOrderItemsToShipmentItem($orderItems, $items);
        }
        unset($shipment);
        return $shipments;
    }

    /**
     * @param int $id
     * @return array
     */
    public static function geShipmentWithJoinedItems($id, $orderId)
    {
        $shipmentRepo = Plugin::instance()->getShipmentRepo();
        $shipmentItemRepo = Plugin::instance()->getShipmentItemsRepo();
        $order = wc_get_order($orderId);
        $orderItems = $order->get_items();
        $shipment = $shipmentRepo->find($id);
        $shipmentItems = $shipmentItemRepo->findBy(['shipment.id' => $shipment['id']]);
        $shipment['items'] = self::mapOrderItemsToShipmentItem($orderItems, $shipmentItems);

        return $shipment;
    }


    public static function isBulkSynchronizationInProcess()
    {
        global $wpdb;
        $activeTasks = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM ".($wpdb->prefix.'trackmage_background_task')." WHERE action = %s AND (status = %s OR status = %s)","trackmage_bulk_orders_sync", "processing", "new" ) , ARRAY_A );
        return is_array($activeTasks) && count($activeTasks) > 0;
    }

    /**
     * @param array $shipment
     * @return array
     */
    public static function saveShipmentWithJoinedItems(array $shipment)
    {
        $shipmentRepo = Plugin::instance()->getShipmentRepo();
        $shipmentId = isset($shipment['id']) ? $shipment['id'] : null;
        if (null !== $shipmentId) {
            unset($shipment['id']);
            $shipment = $shipmentRepo->update($shipmentId, $shipment);
            do_action('trackmage_update_shipment', $shipmentId);
        } else {
            $shipment = $shipmentRepo->insert($shipment);
            do_action('trackmage_new_shipment', $shipment['id']);
        }
        if ($shipment === null) {
            throw new RuntimeException('Unable to save shipment');
        }
        return $shipment;
    }

    /**
     * @param int $shipmentId
     */
    public static function deleteShipment($shipmentId)
    {
        $shipmentRepo = Plugin::instance()->getShipmentRepo();
        do_action('trackmage_delete_shipment', $shipmentId);
        $shipmentRepo->delete($shipmentId);
    }

    public static function getOrderStatuses() {
        $statuses = [];
        $get_statuses = wc_get_order_statuses();

        $custom_statuses = get_option( 'trackmage_custom_order_statuses', [] );
        $aliases = get_option( 'trackmage_order_status_aliases', [] );

        foreach ( $get_statuses as $slug => $name ) {
            $statuses[ $slug ] = [
                'name' => $name,
                'is_custom' => array_key_exists( $slug, $custom_statuses ),
                'alias' => array_key_exists( $slug, $aliases ) ? $aliases[ $slug ] : '',
            ];
        }

        return $statuses;
    }

    /**
     * Get order status details by slug.
     *
     * @since 0.1.0
     *
     * @param $slug Status slug.
     * @return array|null Status details or null if not found.
     */
    public static function get_order_status_by_slug( $slug ) {
        $statuses = self::getOrderStatuses();
        return isset( $statuses[ $slug ] ) ? $statuses[ $slug ] : null;
    }

    /**
     * Returns all the sent HTTP hearders.
     *
     * @since 0.1.0
     * @return array Array of headers.
     */
    public static function getallheaders() {
        $headers = array();

        foreach ( $_SERVER as $name => $value ) {
            if ( strpos( $name, 'HTTP_' ) === 0 ) {
                $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
            }
        }

        return $headers;
    }

    /**
     * Returns endpoint URL.
     *
     * @since 0.1.0
     * @return string Endpoint URL.
     */
    public static function get_endpoint() {
        return get_site_url( null, '/?trackmage=callback' );
    }

    /**
     * Prints out CSS classes if a condition is met.
     *
     * @param boolean $condition The condition to check against (default: false).
     * @param string $class Classes to print out (default: '').
     * @param bool $leading_space Whether to add a leading space (default: false).
     * @param bool $echo Whether to echo or return the output (default: false).
     *
     * @return string
     * @since 0.1.0
     *
     */
    public static function add_css_class( $condition = false, $class = '', $leading_space = false, $echo = false ) {
        if ( $condition ) {
            $output = ( $leading_space ? ' ' : '' ) . $class;

            if ( $echo ) {
                echo $output;
            } else {
                return  $output;
            }
        }
    }

    /**
     * Generates HTML tag attributes if their value is not empty.
     *
     * The leading and trailing spaces will not be printed out if all attributes have empty values.
     *
     * @since 0.1.0
     *
     * @param array $atts           Attributes and their values.
     * @param bool  $leading_space  Whether to add a leading space (default: false).
     * @param bool  $trailing_space Whether to add a trailing space (default: false).
     * @param bool  $echo           Whether to echo or return the output (default: false).
     * @return string Tag attributes.
     */
    public static function generate_html_tag_atts( $atts, $leading_space = false, $trailing_space = false, $echo = false ) {
        $output =  '';
        $atts_count = 0;

        foreach ( $atts as $attr => $value ) {
            if ( ! empty( $value ) ) {
                $atts_count++;
                $output .=  $attr . '="' . $value . '"';
            }
        }

        if ( 0 < $atts_count ) {
            $output = ( $leading_space ? ' ' : '' ) . $output . ( $trailing_space ? ' ' : '' );

            if ( $echo ) {
                echo $output;
            } else {
                return $output;
            }
        }
    }

    /**
     * Generates inline style string.
     *
     * @since 0.1.0
     *
     * @param array $props Array of CSS properties and their values.
     * @param bool  $echo  Whether to echo or return the output (default: false).
     * @return string Inline style string.
     */
    public static function generate_inline_style( $props, $echo = false ) {
        $output = '';
        foreach( $props as $prop => $value ) {
            if ( ! empty( $value ) ) {
                $output .= "{$prop}:{$value};";
            }
        }

        if ( $echo ) {
            echo $output;
        } else {
            return $output;
        }
    }

    /**
     * Return all screen ids of the pages created or modified by the plugin.
     *
     * @since 1.0.0
     * @return array
     */
    public static function getScreenIds() {
        $screenIds = [
            'toplevel_page_trackmage-settings',
            'trackmage_page_trackmage-status-manager',
            'shop_order'
        ];

        return $screenIds;
    }

    /**
     * Retrieves a post meta field for the given post ID.
     *
     * This helper function works like the native `get_post_meta()`, but
     * also returns the post meta ID.
     *
     * @param int    $post_id    The post ID.
     * @param string $meta_key   The meta key.
     * @param string $meta_value Optional. The meta value (default: '').
     *
     * @since 1.0.0
     *
     * @return array|bool Array of meta fields or false if no results found.
     */
    public static function get_post_meta( $post_id, $meta_key, $meta_value = '' ) {
        global $wpdb;
        if ( '' !== $meta_value ) {
            $results = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s AND meta_value = %s", $post_id, $meta_key, $meta_value ) );
        } else {
            $results = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s", $post_id, $meta_key ) );
        }
        $post_meta = [];
        foreach ( $results as $pm ) {
            $post_meta[ $pm->meta_id ] = maybe_unserialize( $pm->meta_value );
        }
        return $post_meta;
    }

    /**
     * Schedule next background task.
     *
     * @param int   $delay  Delay before run scheduled task
     *
     * @return int|bool
     */
    public static function scheduleNextBackgroundTask($delay = 0)
    {
        $backgroundTaskRepo = Plugin::instance()->getBackgroundTaskRepo();
        $activeTask = $backgroundTaskRepo->findOneBy(['status'=>'processing']);
        if(isset($activeTask['id']))
            return false;
        $nextTasks = $backgroundTaskRepo->getQuery('SELECT * FROM _TBL_ WHERE status="new" ORDER BY priority, id LIMIT 1');
        $nextTask = is_array($nextTasks) && count($nextTasks) > 0 ? $nextTasks[0] : null;
        if($nextTask === null){
            return false;
        }
        if(!wp_get_scheduled_event($nextTask->action, [ json_decode($nextTask->params) , $nextTask->id ])){
            $scheduled = wp_schedule_single_event( time() + $delay, $nextTask->action, [ json_decode($nextTask->params) , $nextTask->id ] );
        }
        return $nextTask->id;
    }

    /**
     * @return int
     */
    public static function getBgOrdersAmountToProcess() {
        $backgroundTaskRepo = Plugin::instance()->getBackgroundTaskRepo();
        $tasks = $backgroundTaskRepo->getQuery('SELECT * FROM _TBL_ WHERE status="new" OR status="processing"');
        $count = 0;
        foreach ($tasks as $task) {
            $ids = json_decode($task->params, true);
            $count += is_array($ids) ? count($ids) : 0;
        }
        return $count;
    }

    public static function clearOptions()
    {
        $options_to_clear = array(
            'trackmage_client_id',
            'trackmage_client_secret',
            'trackmage_workspace',
            'trackmage_team',
            'trackmage_sync_statuses',
            'trackmage_sync_start_date',
            'trackmage_webhook',
            'trackmage_integration',
            'trackmage_webhook_username',
            'trackmage_webhook_password',
            'trackmage_custom_order_statuses',
            'trackmage_order_status_aliases',
            'trackmage_modified_order_statuses',
        );
        foreach($options_to_clear as $option){
            delete_option($option);
        }
    }


    public static function clearTransients()
    {
        $transients_to_clear = array(
            'trackmage_order_statuses',
            'trackmage_carriers',
            'trackmage_workspaces'
        );
        foreach ( $transients_to_clear as $transient ) {
            delete_transient( $transient );
        }
    }

    public static function getAllOrdersIds()
    {
        $statuses = get_option('trackmage_sync_statuses', []);
        $startDate = get_option('trackmage_sync_start_date', null);

        $args = array(
            'type' => 'shop_order',
            'limit' => -1,
            'return' => 'ids',
        );
        if (!empty($statuses)) {
            $args['status'] = $statuses;
        }
        if (!empty($startDate)) {
            $args['date_created'] = $startDate . '...' . date('Y-m-d');
        }
        return wc_get_orders($args);
    }

    public static function getAllProductIds()
    {
        $args = array(
            'limit' => -1,
            'return' => 'ids'
        );
        return wc_get_products($args);
    }

    public static function registerCustomStatus($code, $title){
        register_post_status( $code, array(
            'label' => _x( $title, 'WooCommerce Order status', 'trackmage' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( $title.' (%s)', $title.' (%s)', 'trackmage' )
        ) );
    }

    public static function canSync() {
        $credentialsIsValid = self::check_credentials();
        $workspace = get_option( 'trackmage_workspace', 0 );
        return $credentialsIsValid && $workspace !== 0;
    }

    public static function mapOrderItemsToShipmentItem(array $orderItems, array $shipmentItems) {
        return array_map(function($shipmentItem) use ($orderItems){
            $tmOrderItem = explode('/', $shipmentItem['orderItem']);
            $tmOrderItemId = end($tmOrderItem);
            foreach ($orderItems as $orderItemId => $order_item){
                $externalSyncId = wc_get_order_item_meta($orderItemId, '_trackmage_order_item_id', true);
                if ($externalSyncId === $tmOrderItemId){
                    $shipmentItem['order_item_id'] = $orderItemId;
                    return $shipmentItem;
                }
            }
            return $shipmentItem;
        }, $shipmentItems);
    }

    public static function unlinkAllOrders()
    {
        $allOrdersIds = self::getAllOrdersIds();
        foreach ( $allOrdersIds as $orderId ) {
            Plugin::instance()->getSynchronizer()->unlinkOrder( $orderId );
        }
    }

    public static function unlinkAllProducts()
    {
        $allProductsIds = self::getAllProductIds();
        foreach ( $allProductsIds as $productId ) {
            Plugin::instance()->getSynchronizer()->unlinkProduct( $productId );
        }
    }

    /**
     * Retrieves a post meta field for the given post ID.
     *
     * @param int    $productId    The product ID.
     *
     * @since 1.0.7
     *
     * @return array|null Array of order item IDs
     */
    public static function getSyncedOrderItemsByProduct( int $productId ) {
        global $wpdb;
        $results = $wpdb->get_results(
            "select order_item_id from {$wpdb->prefix}woocommerce_order_itemmeta where order_item_id IN (select order_item_id from {$wpdb->prefix}wc_order_product_lookup where product_id = {$productId} OR variation_id = {$productId}) AND meta_key = '_trackmage_order_item_id'",
            ARRAY_A);
        return $results;
    }
}
