USE hexbay;

-- Step 2 preserves the HexBot setup identity after checkout and records only
-- simulated payment metadata. No card number, bank credential, or payment
-- secret is collected by this academic marketplace implementation.

ALTER TABLE orders
    ADD COLUMN payment_method
        ENUM('cash_on_delivery', 'card_simulation', 'bank_transfer_simulation')
        NOT NULL DEFAULT 'cash_on_delivery' AFTER grand_total,
    ADD COLUMN payment_status
        ENUM('not_collected', 'simulated_authorized')
        NOT NULL DEFAULT 'not_collected' AFTER payment_method,
    ADD COLUMN simulated_payment_notice_accepted_at TIMESTAMP NULL
        AFTER payment_status;

ALTER TABLE hexbot_setups
    ADD COLUMN order_id BIGINT UNSIGNED NULL AFTER cart_id,
    ADD CONSTRAINT fk_hexbot_setup_order FOREIGN KEY (order_id)
        REFERENCES orders (id) ON DELETE SET NULL,
    ADD INDEX idx_hexbot_setup_order (order_id, status);
