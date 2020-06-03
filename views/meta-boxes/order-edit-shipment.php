<?php
/**
 * Edit order shipment
 *
 * @package TrackMage\WordPress
 * @author  TrackMage
 */

defined( 'WPINC' ) || exit;

use TrackMage\WordPress\Helper;

$carriers = Helper::get_shipment_carriers();
?>
<?php if (isset($shipment)) : ?>
<div class="trackmage-edit-row">
    <div class="trackmage-edit-row__cols">
        <fieldset class="trackmage-edit-row__col">
            <legend class="trackmage-edit-row__legend"><?php _e('Edit Shipment', 'trackmage'); ?></legend>
            <div class="trackmage-edit-row__fields">
                <input type="hidden" name="id" value="<?php echo esc_attr($shipment['id']); ?>" />
                <label class="trackmage-edit-row__field">
                    <div class="trackmage-edit-row__field-title"><?php _e('Tracking Number', 'trackmage'); ?></div>
                    <div class="trackmage-edit-row__field-wrap"><input type="text" name="tracking_number" value="<?php echo esc_attr($shipment['trackingNumber']); ?>" /></div>
                </label>
                <label class="trackmage-edit-row__field">
                    <div class="trackmage-edit-row__field-title"><?php _e('Carrier', 'trackmage'); ?></div>
                    <div class="trackmage-edit-row__field-wrap">
                        <select name="carrier" data-placeholder="Select a carrier">
                            <?php foreach ($carriers as $carrier ) : ?>
                            <option value="<?php echo esc_attr($carrier['code']); ?>"><?php echo esc_attr($carrier['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </label>
            </div>
        </fieldset>
        <fieldset class="trackmage-edit-row__col">
            <div class="trackmage-edit-row__fields">
                <div class="trackmage-edit-row__field trackmage-edit-row__field--block">
                    <div class="trackmage-edit-row__field-title trackmage-edit-row__field-title--block"><?php _e('Items', 'trackmage'); ?></div>
                    <div class="trackmage-edit-row__field-wrap trackmage-edit-row__field-wrap--block">
                        <div class="items">
                            <div class="items__rows">
                                <?php foreach($shipment['items'] as $item): ?>
                                    <?php include 'order-add-shipment-items-row.php'; ?>
                                <?php endforeach; ?>
                                <button class="button button-secondary items__btn-add-row"><?php _e('Add Row', 'trackmage'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </fieldset>
    </div>
</div>
<?php endif; ?>
