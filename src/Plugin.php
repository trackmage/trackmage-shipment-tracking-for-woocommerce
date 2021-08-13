<?php
/**
 * Main class
 *
 * The main class of the plugin.
 *
 * @package TrackMage\WordPress
 * @author  TrackMage
 */

namespace TrackMage\WordPress;

use TrackMage\WordPress\Admin\Admin;
use TrackMage\WordPress\Admin\Wizard;
use TrackMage\WordPress\Admin\Orders;
use TrackMage\WordPress\Synchronization\ProductSync;
use TrackMage\WordPress\Webhook\Endpoint;
use TrackMage\Client\TrackMageClient;
use TrackMage\WordPress\Repository\EntityRepositoryInterface;
use TrackMage\WordPress\Repository\LogRepository;
use TrackMage\WordPress\Repository\ShipmentItemRepository;
use TrackMage\WordPress\Repository\ShipmentRepository;
use TrackMage\WordPress\Repository\BackgroundTaskRepository;
use TrackMage\WordPress\Synchronization\OrderItemSync;
use TrackMage\WordPress\Synchronization\OrderSync;
use TrackMage\WordPress\Synchronization\ShipmentItemSync;
use TrackMage\WordPress\Synchronization\ShipmentSync;
use TrackMage\WordPress\Webhook\Mappers\OrdersMapper;

/**
 * Main plugin class.
 *
 * @since   0.1.0
 */
class Plugin {
    /** @var ShipmentRepository|null */
    private $shipmentRepo;

    /** @var ShipmentItemRepository|null */
    private $shipmentItemRepo;

    /** @var LogRepository|null */
    private $logRepo;

    /** @var BackgroundTaskRepository|null */
    private $backgroundTaskRepo;

    /** @var Logger|null */
    private $logger;

    /** @var OrderSync|null */
    private $orderSync;

    /** @var OrderItemSync|null */
    private $orderItemSync;

    /** @var ShipmentSync|null */
    private $shipmentSync;

    /** @var ShipmentItemSync|null */
    private $shipmentItemSync;

    /** @var ProductSync|null */
    private $productSync;

    private $wpdb;

    private $dropOnDeactivate = true;

    /**
     * Static instance of the plugin.
     *
     * @since 0.1.0
     *
     * @var self
     */
    protected static $instance;

    /**
     * The singleton instance of TrackMageClient.
     *
     * @since 0.1.0
     * @var TrackMageClient
     */
    protected static $client = null;

    /** @var Synchronizer */
    private $synchronizer;

    /** @var Endpoint */
    private $endpoint;

    /** @var OrdersMapper|null */
    private $ordersMapper;

    /**
     * @var Shortcodes | null
     */
    private $shortcodes;


    /**
     * @param \wpdb $wpdb
     */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;

        $this->bindEventDeleteShipment();
    }

    private function bindEventDeleteShipment()
    {
        add_action('before_delete_post', function ($postId) {
            $type = get_post_type($postId);
            if ($type === 'shop_order'){
                foreach ($this->getShipmentRepo()->findBy(['order_id' => $postId]) as $shipment) {
                    Helper::deleteShipment($shipment['id']);
                }
            }
        }, 10, 1);
    }

    /**
     * Returns the singleton instance of TrackMageClient.
     *
     * Ensures only one instance of TrackMageClient is/can be loaded.
     *
     * @since 0.1.0
     * @return TrackMageClient
     */
    public static function get_client($config = []) {
        if ( null === self::$client ) {
            $client_id = isset( $config['client_id'] ) ? $config['client_id'] : get_option( 'trackmage_client_id', '' );
            $client_secret = isset( $config['client_secret'] ) ? $config['client_secret'] : get_option( 'trackmage_client_secret', '' );
            self::$client = new TrackMageClient( $client_id, $client_secret, null, TRACKMAGE_API_DOMAIN );
        }

        return self::$client;
    }

    /**
     * @return Synchronizer
     */
    public function getSynchronizer()
    {
        if ($this->synchronizer === null) {
            $this->synchronizer = new Synchronizer($this->getLogger(), $this->getOrderSync(), $this->getOrderItemSync(),
                $this->getProductSync(), $this->getBackgroundTaskRepo());
        }

        return $this->synchronizer;
    }

    /**
     * @return Endpoint
     */
    public function getEndpoint()
    {
        if($this->endpoint === null){
            $this->endpoint = new Endpoint($this->getLogger(), $this->getOrdersMapper());
        }

        return $this->endpoint;
    }

    /**
     * @return self
     */
    public static function instance() {
        if(null === self::$instance) {
            global $wpdb;
            self::$instance = new self($wpdb);
        }
        return self::$instance;
    }

    /**
     * Launch the initialization process.
     *
     * @since 0.1.0
     */
    public function init() {
        $this->getSynchronizer();
        $this->getEndpoint();
        // Initialize classes.
        new Admin;
        new Wizard;
        new Orders();
        new TrackingInfo();

        $initClasses = [
            'Ajax',
            'Assets',
            'Admin\Assets',
        ];

        foreach ( $initClasses as $class ) {
            call_user_func( [ __NAMESPACE__ . "\\{$class}", 'init' ] );
        }

        Helper::scheduleNextBackgroundTask(1);

        if(Helper::canSync()) {
            $this->getShortcodes()->init();
        }
    }

    /**
     * @return ShipmentRepository
     */
    public function getShipmentRepo() {
        if($this->shipmentRepo === null) {
            $this->shipmentRepo = new ShipmentRepository($this->getShipmentSync());
        }
        return $this->shipmentRepo;
    }

    /**
     * @return ShipmentItemRepository
     */
    public function getShipmentItemsRepo() {
        if($this->shipmentItemRepo === null) {
            $this->shipmentItemRepo = new ShipmentItemRepository($this->getShipmentItemSync());
        }
        return $this->shipmentItemRepo;
    }

    /**
     * @return LogRepository
     */
    public function getLogRepo() {
        if($this->logRepo === null) {
            $this->logRepo = new LogRepository($this->wpdb, $this->dropOnDeactivate);
        }
        return $this->logRepo;
    }

    /**
     * @return BackgroundTaskRepository
     */
    public function getBackgroundTaskRepo() {
        if($this->backgroundTaskRepo === null) {
            $this->backgroundTaskRepo = new BackgroundTaskRepository($this->wpdb, $this->dropOnDeactivate);
        }
        return $this->backgroundTaskRepo;
    }

    /**
     * @return Logger
     */
    public function getLogger() {
        if($this->logger === null) {
            $this->logger = new Logger($this->getLogRepo());
        }
        return $this->logger;
    }

    /**
     * @return EntityRepositoryInterface[]
     */
    public function getRepos() {
        return [$this->getLogRepo(), $this->getBackgroundTaskRepo()];
    }

    public function getIntegrationId()
    {
        return get_option('trackmage_integration','');
    }

    /**
     * @return OrderSync
     */
    public function getOrderSync()
    {
        if (null === $this->orderSync) {
            $this->orderSync = new OrderSync($this->getIntegrationId());
        }
        return $this->orderSync;
    }

    /**
     * @return ShipmentSync
     */
    public function getShipmentSync()
    {
        if (null === $this->shipmentSync) {
            $this->shipmentSync = new ShipmentSync($this->getIntegrationId());
        }
        return $this->shipmentSync;
    }

    /**
     * @return ShipmentItemSync
     */
    public function getShipmentItemSync()
    {
        if (null === $this->shipmentItemSync) {
            $this->shipmentItemSync = new ShipmentItemSync();
        }
        return $this->shipmentItemSync;
    }

    /**
     * @return OrderItemSync
     */
    public function getOrderItemSync()
    {
        if (null === $this->orderItemSync) {
            $this->orderItemSync = new OrderItemSync($this->getIntegrationId());
        }
        return $this->orderItemSync;
    }

    /**
     * @return ProductSync|null
     */
    public function getProductSync()
    {
        if (null === $this->productSync) {
            $this->productSync = new ProductSync($this->getIntegrationId());
        }
        return $this->productSync;
    }

    /**
     * @return OrdersMapper
     */
    public function getOrdersMapper()
    {
        if (null === $this->ordersMapper) {
            $this->ordersMapper = new OrdersMapper($this->getIntegrationId());
        }

        return $this->ordersMapper;
    }

    public function dropOldTables(){
        $oldTables = ['trackmage_shipment_item', 'trackmage_shipment'];
        foreach ($oldTables as $oldTable){
            $tableName = $this->wpdb->prefix.$oldTable;
            $this->wpdb->query("DROP TABLE IF EXISTS `{$tableName}`");
        }
    }

    /**
     * @return Shortcodes
     */
    public function getShortcodes()
    {
        if($this->shortcodes === null){
            $this->shortcodes = new Shortcodes();
        }
        return $this->shortcodes;
    }

}
