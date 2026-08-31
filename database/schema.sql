-- Hexbay Sprint 1 database schema
-- Target: MySQL 8.0.16+ (tested design target: MySQL 8.3)
-- Financial records in this database are simulations only.

CREATE DATABASE IF NOT EXISTS hexbay
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE hexbay;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Identity, authentication, profiles, and governance
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_roles_name UNIQUE (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('pending', 'active', 'suspended', 'deactivated') NOT NULL DEFAULT 'active',
    email_verified_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id),
    INDEX idx_users_role_status (role_id, status),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS administrator_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_profiles_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    phone VARCHAR(30) NULL,
    avatar_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_profiles_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_owner_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    phone VARCHAR(30) NULL,
    business_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shop_owner_profiles_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(60) NOT NULL,
    recipient_name VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address_line_1 VARCHAR(190) NOT NULL,
    address_line_2 VARCHAR(190) NULL,
    city VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    postal_code VARCHAR(20) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'LK',
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    INDEX idx_addresses_customer (customer_user_id, is_default)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_revoked_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jti_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_revoked_tokens_jti UNIQUE (jti_hash),
    CONSTRAINT fk_revoked_tokens_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    INDEX idx_revoked_tokens_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_throttle (email_hash, ip_address, succeeded, attempted_at),
    INDEX idx_login_attempt_cleanup (attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(80) NOT NULL,
    resource_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_audit_actor_created (actor_user_id, created_at),
    INDEX idx_audit_resource (resource_type, resource_id, created_at),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value JSON NOT NULL,
    description VARCHAR(255) NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_system_settings_user FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Vendors and verification
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS shops (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    description TEXT NULL,
    address_text VARCHAR(500) NULL,
    contact_phone VARCHAR(30) NULL,
    contact_email VARCHAR(190) NULL,
    logo_path VARCHAR(255) NULL,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'suspended', 'inactive')
        NOT NULL DEFAULT 'draft',
    status_reason VARCHAR(500) NULL,
    rating_average DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_shops_owner UNIQUE (owner_user_id),
    CONSTRAINT uq_shops_slug UNIQUE (slug),
    CONSTRAINT fk_shops_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT chk_shop_rating CHECK (rating_average BETWEEN 0.00 AND 5.00),
    INDEX idx_shops_status_rating (status, rating_average)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendor_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    submission_number INT UNSIGNED NOT NULL DEFAULT 1,
    legal_name VARCHAR(190) NOT NULL,
    identity_reference VARCHAR(190) NULL,
    business_registration_reference VARCHAR(190) NULL,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'suspended')
        NOT NULL DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    review_notes TEXT NULL,
    decision_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_verification_submission UNIQUE (shop_id, submission_number),
    CONSTRAINT fk_verifications_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_verifications_reviewer FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_verifications_status_submitted (status, submitted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verification_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    verification_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    sha256_hash CHAR(64) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_verification_document_storage UNIQUE (stored_filename),
    CONSTRAINT fk_verification_documents_verification FOREIGN KEY (verification_id)
        REFERENCES vendor_verifications (id) ON DELETE CASCADE,
    INDEX idx_verification_documents_parent (verification_id, document_type)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Catalogue and structured specifications
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    requires_listing_approval BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_categories_slug UNIQUE (slug),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id)
        REFERENCES categories (id) ON DELETE SET NULL,
    INDEX idx_categories_parent_active (parent_id, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    aliases_json JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_brands_name UNIQUE (name),
    CONSTRAINT uq_brands_slug UNIQUE (slug)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS canonical_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    brand_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    model VARCHAR(150) NOT NULL,
    manufacturer_part_number VARCHAR(120) NULL,
    specification_completeness ENUM('incomplete', 'partial', 'complete')
        NOT NULL DEFAULT 'incomplete',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_canonical_product_identity UNIQUE (category_id, brand_id, model),
    CONSTRAINT fk_canonical_products_category FOREIGN KEY (category_id)
        REFERENCES categories (id),
    CONSTRAINT fk_canonical_products_brand FOREIGN KEY (brand_id)
        REFERENCES brands (id),
    CONSTRAINT fk_canonical_products_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_canonical_products_catalogue (category_id, brand_id, is_active),
    FULLTEXT INDEX ft_canonical_product_search (name, model, manufacturer_part_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS specification_definitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    data_type ENUM('text', 'integer', 'decimal', 'boolean', 'option', 'multi_option')
        NOT NULL,
    unit VARCHAR(30) NULL,
    is_required BOOLEAN NOT NULL DEFAULT FALSE,
    is_filterable BOOLEAN NOT NULL DEFAULT TRUE,
    is_compatibility_field BOOLEAN NOT NULL DEFAULT FALSE,
    minimum_value DECIMAL(18,4) NULL,
    maximum_value DECIMAL(18,4) NULL,
    validation_pattern VARCHAR(500) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_spec_definition_code UNIQUE (category_id, code),
    CONSTRAINT fk_spec_definitions_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE CASCADE,
    CONSTRAINT chk_spec_definition_range CHECK (
        minimum_value IS NULL OR maximum_value IS NULL OR minimum_value <= maximum_value
    ),
    INDEX idx_spec_definitions_category (category_id, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS specification_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    definition_id BIGINT UNSIGNED NOT NULL,
    value_code VARCHAR(100) NOT NULL,
    display_value VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_spec_option_value UNIQUE (definition_id, value_code),
    CONSTRAINT fk_spec_options_definition FOREIGN KEY (definition_id)
        REFERENCES specification_definitions (id) ON DELETE CASCADE,
    INDEX idx_spec_options_definition (definition_id, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_specifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    definition_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NULL,
    value_text VARCHAR(500) NULL,
    value_number DECIMAL(18,4) NULL,
    value_boolean BOOLEAN NULL,
    value_json JSON NULL,
    source_note VARCHAR(255) NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_product_specification UNIQUE (canonical_product_id, definition_id),
    CONSTRAINT fk_product_specs_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_product_specs_definition FOREIGN KEY (definition_id)
        REFERENCES specification_definitions (id),
    CONSTRAINT fk_product_specs_option FOREIGN KEY (option_id)
        REFERENCES specification_options (id),
    CONSTRAINT fk_product_specs_updater FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_product_specs_definition_number (definition_id, value_number),
    INDEX idx_product_specs_option (definition_id, option_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    tag_type ENUM('intended_use', 'feature') NOT NULL DEFAULT 'feature',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_product_tags_code UNIQUE (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS canonical_product_tags (
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    weight DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    PRIMARY KEY (canonical_product_id, tag_id),
    CONSTRAINT fk_product_tags_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_product_tags_tag FOREIGN KEY (tag_id)
        REFERENCES product_tags (id) ON DELETE CASCADE,
    CONSTRAINT chk_product_tag_weight CHECK (weight BETWEEN 0.0000 AND 1.0000)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_product_listings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL,
    condition_type ENUM('new', 'used', 'refurbished') NOT NULL DEFAULT 'new',
    price DECIMAL(13,2) NOT NULL,
    vendor_description TEXT NULL,
    warranty_summary VARCHAR(255) NULL,
    status ENUM('draft', 'pending_approval', 'active', 'rejected', 'hidden', 'flagged', 'inactive')
        NOT NULL DEFAULT 'draft',
    status_reason VARCHAR(500) NULL,
    approved_by_user_id BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_listing_shop_sku UNIQUE (shop_id, sku),
    CONSTRAINT uq_listing_shop_product_condition UNIQUE (
        shop_id, canonical_product_id, condition_type
    ),
    CONSTRAINT fk_listings_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_listings_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id),
    CONSTRAINT fk_listings_approver FOREIGN KEY (approved_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_listing_price CHECK (price >= 0.00),
    INDEX idx_listings_public (status, canonical_product_id, price),
    INDEX idx_listings_shop_status (shop_id, status),
    FULLTEXT INDEX ft_listing_description (vendor_description)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    alt_text VARCHAR(190) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_product_images_storage UNIQUE (stored_filename),
    CONSTRAINT fk_product_images_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id) ON DELETE CASCADE,
    INDEX idx_product_images_listing (listing_id, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS listing_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    rule_code VARCHAR(100) NOT NULL,
    rule_version VARCHAR(30) NOT NULL,
    severity ENUM('low', 'medium', 'high') NOT NULL,
    observed_value VARCHAR(500) NULL,
    explanation VARCHAR(500) NOT NULL,
    status ENUM('open', 'dismissed', 'actioned') NOT NULL DEFAULT 'open',
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_listing_flags_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id) ON DELETE CASCADE,
    CONSTRAINT fk_listing_flags_reviewer FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_listing_flags_review (status, severity, created_at),
    INDEX idx_listing_flags_listing (listing_id, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Inventory, carts, and wishlists
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    quantity_on_hand INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_reserved INT UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 3,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_inventory_listing UNIQUE (listing_id),
    CONSTRAINT fk_inventory_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id) ON DELETE CASCADE,
    CONSTRAINT chk_inventory_reserved CHECK (quantity_reserved <= quantity_on_hand),
    INDEX idx_inventory_availability (quantity_on_hand, quantity_reserved)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('initial', 'restock', 'sale', 'cancellation', 'adjustment', 'reservation', 'release')
        NOT NULL,
    quantity_delta INT NOT NULL,
    quantity_after INT UNSIGNED NOT NULL,
    reference_type VARCHAR(80) NULL,
    reference_id BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_movements_inventory FOREIGN KEY (inventory_id)
        REFERENCES inventory (id),
    CONSTRAINT fk_inventory_movements_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_inventory_movements_inventory (inventory_id, created_at),
    INDEX idx_inventory_movements_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS carts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active', 'converted', 'abandoned') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_carts_customer FOREIGN KEY (customer_user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    INDEX idx_carts_customer_status (customer_user_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cart_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cart_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_cart_listing UNIQUE (cart_id, listing_id),
    CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id)
        REFERENCES carts (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id),
    CONSTRAINT chk_cart_item_quantity CHECK (quantity > 0),
    INDEX idx_cart_items_listing (listing_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishlists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT 'My Wishlist',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_wishlist_customer_name UNIQUE (customer_user_id, name),
    CONSTRAINT fk_wishlists_customer FOREIGN KEY (customer_user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS wishlist_items (
    wishlist_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (wishlist_id, listing_id),
    CONSTRAINT fk_wishlist_items_wishlist FOREIGN KEY (wishlist_id)
        REFERENCES wishlists (id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_items_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id) ON DELETE CASCADE,
    INDEX idx_wishlist_items_listing (listing_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Orders, trust, and simulated finance
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS commission_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    percentage DECIMAL(5,2) NOT NULL,
    effective_from TIMESTAMP NOT NULL,
    effective_to TIMESTAMP NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_commission_rules_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_commission_percentage CHECK (percentage BETWEEN 0.00 AND 100.00),
    CONSTRAINT chk_commission_dates CHECK (
        effective_to IS NULL OR effective_to > effective_from
    ),
    INDEX idx_commission_rules_effective (effective_from, effective_to)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(30) NOT NULL,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    address_snapshot_json JSON NOT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'LKR',
    grand_total DECIMAL(13,2) NOT NULL,
    status ENUM('pending', 'processing', 'partially_shipped', 'shipped', 'partially_completed', 'completed', 'partially_cancelled', 'cancelled')
        NOT NULL DEFAULT 'pending',
    placed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_orders_number UNIQUE (order_number),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_user_id) REFERENCES users (id),
    CONSTRAINT chk_orders_total CHECK (grand_total >= 0.00),
    INDEX idx_orders_customer_created (customer_user_id, created_at),
    INDEX idx_orders_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendor_sub_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    sub_order_number VARCHAR(40) NOT NULL,
    status ENUM('pending', 'processing', 'shipped', 'completed', 'cancelled')
        NOT NULL DEFAULT 'pending',
    gross_total DECIMAL(13,2) NOT NULL,
    commission_rule_id BIGINT UNSIGNED NOT NULL,
    commission_rate_snapshot DECIMAL(5,2) NOT NULL,
    commission_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    vendor_net_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    processing_at TIMESTAMP NULL,
    shipped_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    cancellation_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_sub_order_number UNIQUE (sub_order_number),
    CONSTRAINT uq_sub_order_shop UNIQUE (order_id, shop_id),
    CONSTRAINT fk_sub_orders_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_sub_orders_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_sub_orders_commission_rule FOREIGN KEY (commission_rule_id)
        REFERENCES commission_rules (id),
    CONSTRAINT chk_sub_order_amounts CHECK (
        gross_total >= 0.00
        AND commission_amount >= 0.00
        AND vendor_net_amount >= 0.00
    ),
    INDEX idx_sub_orders_shop_status (shop_id, status, created_at),
    INDEX idx_sub_orders_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sub_order_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(190) NOT NULL,
    sku_snapshot VARCHAR(100) NOT NULL,
    specification_snapshot_json JSON NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(13,2) NOT NULL,
    line_total DECIMAL(13,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_sub_order FOREIGN KEY (sub_order_id)
        REFERENCES vendor_sub_orders (id),
    CONSTRAINT fk_order_items_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id),
    CONSTRAINT fk_order_items_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id),
    CONSTRAINT chk_order_item_values CHECK (
        quantity > 0 AND unit_price >= 0.00 AND line_total >= 0.00
    ),
    INDEX idx_order_items_sub_order (sub_order_id),
    INDEX idx_order_items_listing (listing_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NULL,
    sub_order_id BIGINT UNSIGNED NULL,
    previous_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_history_order FOREIGN KEY (order_id)
        REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_history_sub_order FOREIGN KEY (sub_order_id)
        REFERENCES vendor_sub_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_order_history_target CHECK (
        (order_id IS NOT NULL AND sub_order_id IS NULL)
        OR (order_id IS NULL AND sub_order_id IS NOT NULL)
    ),
    INDEX idx_order_history_order (order_id, created_at),
    INDEX idx_order_history_sub_order (sub_order_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_item_id BIGINT UNSIGNED NOT NULL,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    canonical_product_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    title VARCHAR(150) NULL,
    review_text TEXT NULL,
    is_verified_purchase BOOLEAN NOT NULL DEFAULT TRUE,
    status ENUM('pending', 'published', 'hidden', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_reviews_order_item UNIQUE (order_item_id),
    CONSTRAINT fk_reviews_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id),
    CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_user_id) REFERENCES users (id),
    CONSTRAINT fk_reviews_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id),
    CONSTRAINT fk_reviews_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    INDEX idx_reviews_product_status (canonical_product_id, status, created_at),
    INDEX idx_reviews_shop_status (shop_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    sub_order_id BIGINT UNSIGNED NULL,
    listing_id BIGINT UNSIGNED NULL,
    shop_id BIGINT UNSIGNED NULL,
    subject VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open', 'under_review', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    assigned_admin_user_id BIGINT UNSIGNED NULL,
    resolution_note TEXT NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_complaints_customer FOREIGN KEY (customer_user_id) REFERENCES users (id),
    CONSTRAINT fk_complaints_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_complaints_sub_order FOREIGN KEY (sub_order_id)
        REFERENCES vendor_sub_orders (id),
    CONSTRAINT fk_complaints_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id),
    CONSTRAINT fk_complaints_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_complaints_admin FOREIGN KEY (assigned_admin_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_complaints_status_created (status, created_at),
    INDEX idx_complaints_customer (customer_user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaint_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    response_text TEXT NOT NULL,
    is_internal BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_complaint_responses_complaint FOREIGN KEY (complaint_id)
        REFERENCES complaints (id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_responses_author FOREIGN KEY (author_user_id)
        REFERENCES users (id),
    INDEX idx_complaint_responses_parent (complaint_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS counterfeit_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NULL,
    reason_code VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    evidence_metadata_json JSON NULL,
    status ENUM('open', 'under_review', 'dismissed', 'actioned') NOT NULL DEFAULT 'open',
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    review_note TEXT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_counterfeit_reporter FOREIGN KEY (reporter_user_id) REFERENCES users (id),
    CONSTRAINT fk_counterfeit_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id),
    CONSTRAINT fk_counterfeit_order_item FOREIGN KEY (order_item_id)
        REFERENCES order_items (id),
    CONSTRAINT fk_counterfeit_reviewer FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_counterfeit_status_created (status, created_at),
    INDEX idx_counterfeit_listing (listing_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payouts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payout_reference VARCHAR(40) NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    requested_by_user_id BIGINT UNSIGNED NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    amount DECIMAL(13,2) NOT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'LKR',
    status ENUM('pending', 'approved', 'paid', 'rejected') NOT NULL DEFAULT 'pending',
    decision_reason VARCHAR(500) NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    rejected_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_payout_reference UNIQUE (payout_reference),
    CONSTRAINT fk_payouts_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_payouts_requester FOREIGN KEY (requested_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_payouts_reviewer FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_payout_amount CHECK (amount > 0.00),
    INDEX idx_payouts_shop_status (shop_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(100) NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    sub_order_id BIGINT UNSIGNED NULL,
    payout_id BIGINT UNSIGNED NULL,
    entry_type ENUM('sale', 'commission', 'refund_adjustment', 'payout') NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'LKR',
    description VARCHAR(500) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_ledger_event_key UNIQUE (event_key),
    CONSTRAINT fk_ledger_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_ledger_order FOREIGN KEY (order_id) REFERENCES orders (id),
    CONSTRAINT fk_ledger_sub_order FOREIGN KEY (sub_order_id)
        REFERENCES vendor_sub_orders (id),
    CONSTRAINT fk_ledger_payout FOREIGN KEY (payout_id) REFERENCES payouts (id),
    CONSTRAINT fk_ledger_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_ledger_amount_nonzero CHECK (amount <> 0.00),
    INDEX idx_ledger_shop_created (shop_id, created_at),
    INDEX idx_ledger_sub_order (sub_order_id, entry_type),
    INDEX idx_ledger_payout (payout_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendor_balance_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id BIGINT UNSIGNED NOT NULL,
    pending_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    available_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    reconciled_through_entry_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_balance_snapshots_shop FOREIGN KEY (shop_id) REFERENCES shops (id),
    CONSTRAINT fk_balance_snapshots_entry FOREIGN KEY (reconciled_through_entry_id)
        REFERENCES ledger_entries (id) ON DELETE SET NULL,
    INDEX idx_balance_snapshots_shop_created (shop_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Notifications, interactions, recommendations, and HexBot persistence
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    related_resource_type VARCHAR(80) NULL,
    related_resource_id BIGINT UNSIGNED NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, read_at, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_interactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    session_key VARCHAR(100) NULL,
    canonical_product_id BIGINT UNSIGNED NULL,
    listing_id BIGINT UNSIGNED NULL,
    event_type ENUM('view', 'search', 'compare', 'wishlist', 'cart', 'purchase', 'rating', 'review')
        NOT NULL,
    event_weight DECIMAL(6,3) NOT NULL,
    context_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_interactions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_interactions_product FOREIGN KEY (canonical_product_id)
        REFERENCES canonical_products (id) ON DELETE SET NULL,
    CONSTRAINT fk_interactions_listing FOREIGN KEY (listing_id)
        REFERENCES shop_product_listings (id) ON DELETE SET NULL,
    INDEX idx_interactions_user_created (user_id, created_at),
    INDEX idx_interactions_product_event (canonical_product_id, event_type, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recommendation_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    session_key VARCHAR(100) NULL,
    recommendation_type VARCHAR(80) NOT NULL,
    algorithm_version VARCHAR(60) NOT NULL,
    request_context_json JSON NOT NULL,
    results_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recommendation_logs_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_recommendation_logs_user_created (user_id, created_at),
    INDEX idx_recommendation_logs_algorithm (algorithm_version, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chatbot_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    session_key VARCHAR(100) NULL,
    active_intent VARCHAR(80) NULL,
    state_code VARCHAR(100) NULL,
    context_json JSON NOT NULL,
    status ENUM('active', 'completed', 'expired') NOT NULL DEFAULT 'active',
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_chatbot_sessions_public UNIQUE (public_id),
    CONSTRAINT fk_chatbot_sessions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_chatbot_sessions_user_status (user_id, status, updated_at),
    INDEX idx_chatbot_sessions_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chatbot_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chatbot_session_id BIGINT UNSIGNED NOT NULL,
    sender ENUM('customer', 'hexbot') NOT NULL,
    message_text VARCHAR(2000) NOT NULL,
    detected_intent VARCHAR(80) NULL,
    confidence_score DECIMAL(6,5) NULL,
    extracted_entities_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chatbot_messages_session FOREIGN KEY (chatbot_session_id)
        REFERENCES chatbot_sessions (id) ON DELETE CASCADE,
    CONSTRAINT chk_chatbot_confidence CHECK (
        confidence_score IS NULL OR confidence_score BETWEEN 0.00000 AND 1.00000
    ),
    INDEX idx_chatbot_messages_session (chatbot_session_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS compatibility_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    rule_version VARCHAR(60) NOT NULL,
    selected_components_json JSON NOT NULL,
    overall_result ENUM('compatible', 'incompatible', 'warning', 'unknown') NOT NULL,
    rule_results_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_compatibility_checks_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    INDEX idx_compatibility_checks_user_created (user_id, created_at),
    INDEX idx_compatibility_checks_version (rule_version, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Reference rows required by Sprint 1 authentication and future modules
-- ---------------------------------------------------------------------------

INSERT INTO roles (name, display_name, is_active)
VALUES
    ('administrator', 'Administrator', TRUE),
    ('shop_owner', 'Tech Shop Owner', TRUE),
    ('customer', 'Customer', TRUE)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    is_active = VALUES(is_active);

INSERT INTO categories
    (name, slug, description, is_active, requires_listing_approval, sort_order)
VALUES
    ('Laptops', 'laptops', 'Complete portable computers.', TRUE, TRUE, 10),
    ('Processors', 'processors', 'Desktop processors and CPUs.', TRUE, TRUE, 20),
    ('Motherboards', 'motherboards', 'Desktop motherboards.', TRUE, TRUE, 30),
    ('Memory', 'memory', 'Desktop RAM modules and kits.', TRUE, TRUE, 40),
    ('Graphics Cards', 'graphics-cards', 'Dedicated desktop GPUs.', TRUE, TRUE, 50),
    ('Power Supplies', 'power-supplies', 'Desktop power supply units.', TRUE, TRUE, 60),
    ('Storage', 'storage', 'HDD, SATA SSD, and NVMe storage.', TRUE, TRUE, 70),
    ('Computer Cases', 'computer-cases', 'Desktop computer enclosures.', TRUE, TRUE, 80),
    ('Accessories', 'accessories', 'Technology accessories.', TRUE, TRUE, 90)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_active = VALUES(is_active),
    requires_listing_approval = VALUES(requires_listing_approval),
    sort_order = VALUES(sort_order);

INSERT INTO product_tags (code, display_name, tag_type)
VALUES
    ('gaming', 'Gaming', 'intended_use'),
    ('office', 'Office Work', 'intended_use'),
    ('programming', 'Programming', 'intended_use'),
    ('graphic_design', 'Graphic Design', 'intended_use'),
    ('video_editing', 'Video Editing', 'intended_use'),
    ('content_creation', 'Content Creation', 'intended_use')
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    tag_type = VALUES(tag_type),
    is_active = TRUE;

INSERT INTO commission_rules
    (percentage, effective_from, reason)
SELECT 5.00, '2026-05-04 00:00:00', 'Initial simulated platform commission'
WHERE NOT EXISTS (SELECT 1 FROM commission_rules);

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES
    ('currency', JSON_OBJECT('code', 'LKR', 'symbol', 'Rs.'), 'Marketplace currency'),
    ('financial_mode', JSON_OBJECT('simulated', TRUE), 'No real payments or payouts'),
    ('compatibility_rule_version', JSON_OBJECT('version', '1.0.0'), 'Initial rule version')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);
