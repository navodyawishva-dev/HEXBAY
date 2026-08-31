USE hexbay;

START TRANSACTION;

INSERT INTO pc_build_optimizer_versions
    (version, compatibility_rule_version, scoring_config_json, status, activated_at)
VALUES
    (
        'pc-build-v1.1.0',
        'pc-compat-v1.0.0',
        JSON_OBJECT(
            'search_strategy', 'compatibility_pruned_beam_search',
            'beam_width', 800,
            'performance_requirement_mix', 0.45,
            'performance_workload_mix', 0.55,
            'balance_weight', 0.10,
            'budget_fit_weight', 0.10,
            'default_flexibility_percent', 7.5,
            'buyer_specification_constraints', JSON_ARRAY(
                'memory_capacity',
                'graphics_memory',
                'processor_family',
                'processor_model',
                'storage_capacity',
                'storage_type'
            )
        ),
        'active',
        CURRENT_TIMESTAMP
    )
ON DUPLICATE KEY UPDATE
    compatibility_rule_version=VALUES(compatibility_rule_version),
    scoring_config_json=VALUES(scoring_config_json),
    status='active',
    activated_at=COALESCE(activated_at, CURRENT_TIMESTAMP);

UPDATE pc_build_optimizer_versions
SET status='retired'
WHERE version<>'pc-build-v1.1.0' AND status='active';

COMMIT;
