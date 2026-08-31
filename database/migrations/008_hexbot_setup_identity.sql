USE hexbay;

CREATE TABLE IF NOT EXISTS hexbot_setups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    cart_id BIGINT UNSIGNED NULL,
    source_recommendation_public_id CHAR(36) NULL,
    name VARCHAR(190) NOT NULL,
    build_rank TINYINT UNSIGNED NOT NULL,
    setup_scope ENUM('pc_only', 'pc_monitor', 'complete_setup') NOT NULL,
    status ENUM('in_cart', 'ordered', 'cancelled') NOT NULL DEFAULT 'in_cart',
    target_budget_lkr DECIMAL(13,2) NOT NULL,
    max_budget_lkr DECIMAL(13,2) NOT NULL,
    selected_total_lkr DECIMAL(13,2) NOT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'LKR',
    requirements_json JSON NOT NULL,
    scores_json JSON NOT NULL,
    compatibility_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_hexbot_setup_public UNIQUE (public_id),
    CONSTRAINT fk_hexbot_setup_customer FOREIGN KEY (customer_user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_hexbot_setup_cart FOREIGN KEY (cart_id)
        REFERENCES carts (id) ON DELETE SET NULL,
    CONSTRAINT fk_hexbot_setup_recommendation FOREIGN KEY (source_recommendation_public_id)
        REFERENCES pc_build_recommendation_requests (public_id) ON DELETE SET NULL,
    CONSTRAINT chk_hexbot_setup_budget CHECK (
        target_budget_lkr > 0
        AND max_budget_lkr >= target_budget_lkr
        AND selected_total_lkr >= 0
    ),
    INDEX idx_hexbot_setup_customer (customer_user_id, created_at),
    INDEX idx_hexbot_setup_cart (cart_id, status),
    INDEX idx_hexbot_setup_recommendation (source_recommendation_public_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hexbot_setup_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setup_id BIGINT UNSIGNED NOT NULL,
    component_group ENUM('pc', 'peripheral') NOT NULL,
    component_code VARCHAR(60) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(190) NOT NULL,
    shop_name_snapshot VARCHAR(150) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price_snapshot DECIMAL(13,2) NOT NULL,
    line_total_snapshot DECIMAL(13,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_hexbot_setup_component UNIQUE (setup_id, component_group, component_code),
    CONSTRAINT fk_hexbot_setup_item_setup FOREIGN KEY (setup_id)
        REFERENCES hexbot_setups (id) ON DELETE CASCADE,
    CONSTRAINT fk_hexbot_setup_item_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id),
    CONSTRAINT fk_hexbot_setup_item_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id),
    CONSTRAINT fk_hexbot_setup_item_shop FOREIGN KEY (shop_id)
        REFERENCES shops (id),
    CONSTRAINT chk_hexbot_setup_item_values CHECK (
        quantity > 0
        AND unit_price_snapshot >= 0
        AND line_total_snapshot >= 0
    ),
    INDEX idx_hexbot_setup_item_setup (setup_id, sort_order),
    INDEX idx_hexbot_setup_item_listing (listing_id),
    INDEX idx_hexbot_setup_item_shop (shop_id)
) ENGINE=InnoDB;
