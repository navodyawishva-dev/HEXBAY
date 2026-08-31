-- Hexbay Sprint 2 migration
-- Adds explicit, auditable seller acceptance of commission terms.

USE hexbay;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS commission_acceptances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_owner_user_id BIGINT UNSIGNED NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    commission_rule_id BIGINT UNSIGNED NOT NULL,
    percentage_snapshot DECIMAL(5,2) NOT NULL,
    terms_version VARCHAR(40) NOT NULL,
    acceptance_text VARCHAR(1000) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_commission_acceptance UNIQUE (
        shop_owner_user_id,
        shop_id,
        commission_rule_id,
        terms_version
    ),
    CONSTRAINT fk_commission_acceptance_owner FOREIGN KEY (shop_owner_user_id)
        REFERENCES users (id),
    CONSTRAINT fk_commission_acceptance_shop FOREIGN KEY (shop_id)
        REFERENCES shops (id),
    CONSTRAINT fk_commission_acceptance_rule FOREIGN KEY (commission_rule_id)
        REFERENCES commission_rules (id),
    CONSTRAINT chk_commission_acceptance_percentage CHECK (
        percentage_snapshot BETWEEN 0.00 AND 100.00
    ),
    INDEX idx_commission_acceptance_shop (shop_id, accepted_at),
    INDEX idx_commission_acceptance_rule (commission_rule_id, accepted_at)
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES (
    'commission_terms',
    JSON_OBJECT(
        'version', '2026-07-v1',
        'summary', 'Platform commission applies to each vendor sub-order completed after customer receipt confirmation.'
    ),
    'Seller-facing commission disclosure'
)
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

