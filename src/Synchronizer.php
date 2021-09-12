<?php

namespace TrackMage\WordPress;

use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Throwable;
use TrackMage\WordPress\Exception\RuntimeException;
use TrackMage\WordPress\Repository\BackgroundTaskRepository;
use TrackMage\WordPress\Synchronization\OrderItemSync;
use TrackMage\WordPress\Synchronization\OrderSync;
use TrackMage\WordPress\Synchronization\ProductSync;

class Synchronizer
{
    const TAG = '[Synchronizer]';

    /** @var bool ignore events */
    private $disableEvents = false;

    private $orderSync;
    private $orderItemSync;
    private $backgroundTaskRepository;

    private $logger;
    /**
     * @var ProductSync
     */
    private $productSync;

    public function __construct(LoggerInterface $logger, OrderSync $orderSync, OrderItemSync $orderItemSync,
        ProductSync $productSync, BackgroundTaskRepository $backgroundTaskRepository)
    {
        $this->logger = $logger;
        $this->orderSync = $orderSync;
        $this->orderItemSync = $orderItemSync;
        $this->productSync = $productSync;
        $this->backgroundTaskRepository = $backgroundTaskRepository;
        $this->bindEvents();
    }

    /**
     * @param bool $disableEvents
     */
    public function setDisableEvents($disableEvents)
    {
        $this->disableEvents = $disableEvents;
    }

    private function bindEvents()
    {
        if(get_transient('trackmage-wizard-notice')) return;
        add_action( 'woocommerce_order_status_changed', [ $this, 'syncOrder' ], 10, 3 );
        add_action( 'woocommerce_new_order', [ $this, 'syncOrder' ], 10, 1 );
        add_action( 'woocommerce_update_order', [ $this, 'syncOrder' ], 10, 1 );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'syncOrder' ], 10, 1 );

        add_action('wp_trash_post', function ($postId) {//woocommerce_trash_order is not fired
            $type = get_post_type($postId);
            if ($type === 'shop_order'){
                $this->syncOrder($postId, true);
            }
        }, 10, 1);
        add_action('before_delete_post', function ($postId) { //woocommerce_delete_order is not fired
            $type = get_post_type($postId);
            if ($type === 'shop_order'){
                $this->deleteOrder($postId);
            }
            if ($type === 'product'){
                $this->deleteProduct($postId);
            }
        }, 10, 1);

        add_action( 'woocommerce_update_product', [ $this, 'syncProduct' ], 10, 1 );
        add_action( 'woocommerce_new_order_item', [ $this, 'syncOrderItem' ], 10, 1 );
        add_action( 'woocommerce_update_order_item', [ $this, 'syncOrderItem' ], 10, 1 );
        add_action( 'woocommerce_delete_order_item', [ $this, 'deleteOrderItem' ], 10, 1 );

        add_action( 'trackmage_bulk_orders_sync', [$this, 'bulkOrdersSync'], 10, 2);
        add_action( 'trackmage_delete_data', [$this, 'deleteData'], 10, 2);

    }

    public function bulkOrdersSync($orderIds = [], $taskId = null){
        try{
            $this->logger->info(self::TAG.'Start to processing orders', ['orderIds'=>$orderIds,'taskId'=>$taskId]);
            if($taskId !== null)
                $this->backgroundTaskRepository->update(['status'=>'processing'],['id'=>$taskId]);

            foreach ($orderIds as $orderId){
                delete_post_meta( $orderId, '_trackmage_hash');
                $this->syncOrder($orderId);
            }

            $this->logger->info(self::TAG.'Processing orders is completed', ['orderIds'=>$orderIds]);
            if($taskId !== null)
                $this->backgroundTaskRepository->update(['status'=>'processed'],['id'=>$taskId]);
            Helper::scheduleNextBackgroundTask();
        }catch (RuntimeException $e){
            $this->logger->warning(self::TAG.'Unable to bulk sync orders', array_merge([
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    public function syncOrder($orderId, $force = false ) {
        $this->logger->info(self::TAG.'Try to sync order.', [
            'order_id' => $orderId,
            'force' => $force
        ]);
        if ($this->disableEvents) {
            $this->logger->info(self::TAG.'Events are disabled. Sync is skipped.', [
                'order_id' => $orderId,
            ]);
            return;
        }
        try {
            $this->orderSync->sync($orderId, $force);

            $order = wc_get_order( $orderId );
            foreach (Helper::getOrderItems($order) as $item) {
                $this->syncOrderItem($item->get_id(), $force);
            }
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to sync remote order', array_merge([
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    public function deleteData($orderIds = [], $taskId = null){
        try{
            $this->logger->info(self::TAG.'Start to delete orders on TrackMage Workspace', ['orderIds'=>$orderIds,'taskId'=>$taskId]);
            if($taskId !== null)
                $this->backgroundTaskRepository->update(['status'=>'processing'],['id'=>$taskId]);

            foreach ($orderIds as $orderId){
                $this->deleteOrder($orderId);
            }

            $this->logger->info(self::TAG.'Orders deletion is completed', ['orderIds'=>$orderIds]);
            if($taskId !== null)
                $this->backgroundTaskRepository->update(['status'=>'processed'],['id'=>$taskId]);
            Helper::scheduleNextBackgroundTask();
        }catch (RuntimeException $e){
            $this->logger->warning(self::TAG.'Unable to delete orders from TrackMage', array_merge([
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    public function deleteOrder($orderId)
    {
        if ($this->disableEvents) {
            return;
        }
        $order = wc_get_order( $orderId );
        foreach (Helper::getOrderItems($order) as $item) { //woocommerce_delete_order_item is not fired on order delete
            $this->deleteOrderItem($item->get_id());
        }

        try {
            $this->orderSync->delete($orderId);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to delete remote order', array_merge([
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    public function unlinkOrder($orderId)
    {
        if ($this->disableEvents) {
            return;
        }
        $order = wc_get_order( $orderId );
        foreach (Helper::getOrderItems($order) as $item) {
            $this->unlinkOrderItem($item->get_id());
        }
        try {
            $this->orderSync->unlink($orderId);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to delete remote order', [
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function syncOrderItem($itemId, $force = false)
    {
        $this->logger->info(self::TAG.'Try to sync order item.', [
            'item_id' => $itemId,
            'force' => $force
        ]);
        if ( $this->disableEvents) {
            return;
        }
        try {
            $item = Helper::getOrderItem($itemId);
            if (null === $item) {
                $this->logger->info(self::TAG.'Order item was not found', [
                    'item_id' => $itemId,
                    'force' => $force,
                ]);
                return;
            }
            if (false !== $product = $item->get_product()) {
                $this->logger->info( self::TAG . 'Try to sync product.', [
                    'product_id' => $product->get_id(),
                    'force'      => $force
                ] );
                $this->productSync->sync( $product->get_id(), $force );
            }
            $this->orderItemSync->sync($itemId, $force);
        } catch (\Exception $e) {
            $this->logger->warning(self::TAG.'Unable to sync remote order item', array_merge([
                'item_id' => $itemId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    public function deleteOrderItem($itemId)
    {
        if ($this->disableEvents) {
            return;
        }
        try {
            $this->orderItemSync->delete($itemId);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to delete remote order item', array_merge([
                'item_id' => $itemId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    public function unlinkOrderItem($itemId)
    {
        if ($this->disableEvents) {
            return;
        }
        try{
            $this->orderItemSync->unlink($itemId);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to unlink order item', [
                'item_id' => $itemId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

    }

    /**
     * Sync Product
     *
     * @param $productId
     * @param false $force
     *
     * @since 1.0.7
     */
    public function syncProduct($productId, $force = false)
    {
        $this->logger->info(self::TAG.'Try to sync product.', [
            'product_id' => $productId,
            'force' => $force
        ]);
        if ( $this->disableEvents || empty(Helper::getSyncedOrderItemsByProduct((int) $productId))) {
            $this->logger->info(self::TAG.'Sync of product is skipped.', [
                'product_id' => $productId
            ]);
            return;
        }
        try {
            $this->productSync->sync($productId, $force);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to sync remote product', array_merge([
                'product_id' => $productId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    /**
     * Delete Product
     *
     * @param $productId
     *
     * @since 1.0.7
     */
    public function deleteProduct($productId)
    {
        $this->logger->info(self::TAG.'Try to delete product.', [
            'product_id' => $productId
        ]);
        if ($this->disableEvents) {
            return;
        }
        try {
            $this->productSync->delete($productId);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to delete remote product', array_merge([
                'product_id' => $productId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], $this->grabGuzzleData($e)));
        }
    }

    /**
     * Unlink Product
     *
     * @param $productId
     *
     * @since 1.0.7
     */
    public function unlinkProduct($productId)
    {
        if ($this->disableEvents) {
            return;
        }
        try{
            $this->productSync->unlink($productId);
        } catch (RuntimeException $e) {
            $this->logger->warning(self::TAG.'Unable to unlink product', [
                'product_id' => $productId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

    }

    /**
     * @param Throwable $e
     * @return array
     */
    private function grabGuzzleData(Throwable $e)
    {
        if ($e instanceof RequestException) {
            $result = [];
            if (null !== $request = $e->getRequest()) {
                $request->getBody()->rewind();
                $content = $request->getBody()->getContents();
                $data = json_decode($content, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    $data = $content;
                }
                $result['request'] = [
                    'method' => $request->getMethod(),
                    'uri' => $request->getUri()->__toString(),
                    'body' => $data,
                ];
            }
            if (null !== $response = $e->getResponse()) {
                $response->getBody()->rewind();
                $content = $response->getBody()->getContents();
                $data = json_decode($content, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    $data = $content;
                }
                $result['response'] = [
                    'status' => $response->getStatusCode(),
                    'body' => $data,
                ];
            }
            return $result;
        }
        $prev = $e->getPrevious();
        if (null !== $prev) {
            return $this->grabGuzzleData($prev);
        }

        return [];
    }
}
