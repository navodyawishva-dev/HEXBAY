USE hexbay;

ALTER TABLE vendor_sub_orders
    ADD COLUMN delivery_method
        ENUM('seller_delivery', 'third_party_courier') NULL
        AFTER cancellation_reason,
    ADD COLUMN delivery_partner VARCHAR(120) NULL AFTER delivery_method,
    ADD COLUMN tracking_reference VARCHAR(120) NULL AFTER delivery_partner,
    ADD COLUMN estimated_delivery_date DATE NULL AFTER tracking_reference,
    ADD COLUMN shipment_note VARCHAR(500) NULL AFTER estimated_delivery_date,
    ADD INDEX idx_sub_orders_tracking (tracking_reference);

CREATE TABLE IF NOT EXISTS vendor_fulfilment_checkpoints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sub_order_id BIGINT UNSIGNED NOT NULL,
    checkpoint_code ENUM(
        'stock_verified',
        'items_packed',
        'delivery_address_verified'
    ) NOT NULL,
    completed_by_user_id BIGINT UNSIGNED NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_vendor_fulfilment_checkpoint
        UNIQUE (sub_order_id, checkpoint_code),
    CONSTRAINT fk_vendor_fulfilment_sub_order FOREIGN KEY (sub_order_id)
        REFERENCES vendor_sub_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_vendor_fulfilment_actor FOREIGN KEY (completed_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_vendor_fulfilment_sub_order (sub_order_id, completed_at)
) ENGINE=InnoDB;
