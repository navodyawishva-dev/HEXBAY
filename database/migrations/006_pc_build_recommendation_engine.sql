USE hexbay;

CREATE TABLE IF NOT EXISTS pc_build_optimizer_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(40) NOT NULL,
    compatibility_rule_version VARCHAR(40) NOT NULL,
    scoring_config_json JSON NOT NULL,
    status ENUM('draft', 'active', 'retired') NOT NULL DEFAULT 'draft',
    activated_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_build_optimizer_version UNIQUE (version),
    INDEX idx_pc_build_optimizer_status (status, activated_at)
) ENGINE=InnoDB;

INSERT INTO pc_build_optimizer_versions
    (version, compatibility_rule_version, scoring_config_json, status, activated_at)
VALUES
    (
        'pc-build-v1.0.0',
        'pc-compat-v1.0.0',
        JSON_OBJECT(
            'search_strategy', 'compatibility_pruned_beam_search',
            'beam_width', 800,
            'performance_requirement_mix', 0.45,
            'performance_workload_mix', 0.55,
            'balance_weight', 0.10,
            'budget_fit_weight', 0.10,
            'default_flexibility_percent', 7.5
        ),
        'active',
        CURRENT_TIMESTAMP
    )
ON DUPLICATE KEY UPDATE
    compatibility_rule_version=VALUES(compatibility_rule_version),
    scoring_config_json=VALUES(scoring_config_json),
    status='active',
    activated_at=COALESCE(activated_at, CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS pc_build_recommendation_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    optimizer_version_id BIGINT UNSIGNED NOT NULL,
    target_budget_lkr DECIMAL(13,2) NOT NULL,
    max_budget_lkr DECIMAL(13,2) NOT NULL,
    workloads_json JSON NOT NULL,
    constraints_json JSON NOT NULL,
    outcome_status ENUM(
        'recommended', 'stretch_only', 'nearest_only', 'no_solution'
    ) NOT NULL,
    generated_combination_count INT UNSIGNED NOT NULL DEFAULT 0,
    compatible_build_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_build_request_public UNIQUE (public_id),
    CONSTRAINT fk_pc_build_request_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pc_build_request_optimizer FOREIGN KEY (optimizer_version_id)
        REFERENCES pc_build_optimizer_versions (id),
    CONSTRAINT chk_pc_build_request_budget CHECK (
        target_budget_lkr > 0 AND max_budget_lkr >= target_budget_lkr
    ),
    INDEX idx_pc_build_request_user (user_id, created_at),
    INDEX idx_pc_build_request_outcome (outcome_status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pc_build_recommendation_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    result_rank TINYINT UNSIGNED NOT NULL,
    budget_tier ENUM('within_target', 'stretch', 'nearest_available') NOT NULL,
    total_price_lkr DECIMAL(13,2) NOT NULL,
    composite_score DECIMAL(6,3) NOT NULL,
    performance_score DECIMAL(6,3) NOT NULL,
    value_score DECIMAL(6,3) NOT NULL,
    compatibility_status ENUM('compatible', 'warning') NOT NULL,
    selected_product_ids_json JSON NOT NULL,
    selected_listing_ids_json JSON NOT NULL,
    explanation_summary_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_pc_build_result_rank UNIQUE (request_id, result_rank),
    CONSTRAINT fk_pc_build_result_request FOREIGN KEY (request_id)
        REFERENCES pc_build_recommendation_requests (id) ON DELETE CASCADE,
    CONSTRAINT chk_pc_build_result_scores CHECK (
        composite_score BETWEEN 0 AND 100
        AND performance_score BETWEEN 0 AND 100
        AND value_score BETWEEN 0 AND 100
    ),
    INDEX idx_pc_build_result_budget (budget_tier, total_price_lkr),
    INDEX idx_pc_build_result_score (composite_score, total_price_lkr)
) ENGINE=InnoDB;
