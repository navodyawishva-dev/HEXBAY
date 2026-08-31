USE hexbay;

-- Step 2 peripheral catalogue taxonomy. These fields describe canonical
-- products only; seller price, stock, condition and warranty remain listing data.

INSERT INTO specification_definitions
    (category_id, code, display_name, data_type, unit, is_required,
     is_filterable, is_compatibility_field, minimum_value, maximum_value,
     sort_order, is_active)
SELECT
    c.id, d.code, d.display_name, d.data_type, d.unit, d.is_required,
    d.is_filterable, FALSE, d.minimum_value, d.maximum_value,
    d.sort_order, TRUE
FROM categories c
INNER JOIN (
    SELECT 'accessories' category_slug, 'connectivity' code,
        'Connectivity' display_name, 'multi_option' data_type, NULL unit,
        FALSE is_required, TRUE is_filterable,
        NULL minimum_value, NULL maximum_value, 20 sort_order
    UNION ALL SELECT 'accessories', 'colour', 'Colour', 'text', NULL,
        FALSE, TRUE, NULL, NULL, 30
    UNION ALL SELECT 'accessories', 'screen_size_inches', 'Screen size',
        'decimal', 'inches', FALSE, TRUE, 10, 100, 100
    UNION ALL SELECT 'accessories', 'resolution_width_pixels',
        'Resolution width', 'integer', 'pixels', FALSE, TRUE, 640, 16384, 110
    UNION ALL SELECT 'accessories', 'resolution_height_pixels',
        'Resolution height', 'integer', 'pixels', FALSE, TRUE, 480, 8640, 120
    UNION ALL SELECT 'accessories', 'refresh_rate_hz', 'Refresh rate',
        'decimal', 'Hz', FALSE, TRUE, 24, 1000, 130
    UNION ALL SELECT 'accessories', 'response_time_ms', 'Response time',
        'decimal', 'ms', FALSE, TRUE, 0.01, 100, 140
    UNION ALL SELECT 'accessories', 'panel_type', 'Panel type',
        'option', NULL, FALSE, TRUE, NULL, NULL, 150
    UNION ALL SELECT 'accessories', 'aspect_ratio', 'Aspect ratio',
        'option', NULL, FALSE, TRUE, NULL, NULL, 160
    UNION ALL SELECT 'accessories', 'adaptive_sync', 'Adaptive sync',
        'option', NULL, FALSE, TRUE, NULL, NULL, 170
    UNION ALL SELECT 'accessories', 'hdr_supported', 'HDR support',
        'boolean', NULL, FALSE, TRUE, NULL, NULL, 180
    UNION ALL SELECT 'accessories', 'keyboard_size', 'Keyboard size',
        'option', NULL, FALSE, TRUE, NULL, NULL, 200
    UNION ALL SELECT 'accessories', 'switch_technology', 'Switch technology',
        'option', NULL, FALSE, TRUE, NULL, NULL, 210
    UNION ALL SELECT 'accessories', 'switch_model', 'Switch model',
        'text', NULL, FALSE, TRUE, NULL, NULL, 220
    UNION ALL SELECT 'accessories', 'backlight_type', 'Backlight type',
        'option', NULL, FALSE, TRUE, NULL, NULL, 230
    UNION ALL SELECT 'accessories', 'has_numpad', 'Numeric keypad',
        'boolean', NULL, FALSE, TRUE, NULL, NULL, 240
    UNION ALL SELECT 'accessories', 'hot_swappable', 'Hot-swappable switches',
        'boolean', NULL, FALSE, TRUE, NULL, NULL, 250
    UNION ALL SELECT 'accessories', 'tracking_method', 'Tracking method',
        'option', NULL, FALSE, TRUE, NULL, NULL, 300
    UNION ALL SELECT 'accessories', 'max_dpi', 'Maximum sensor DPI',
        'integer', 'DPI', FALSE, TRUE, 100, 100000, 310
    UNION ALL SELECT 'accessories', 'hand_orientation', 'Hand orientation',
        'option', NULL, FALSE, TRUE, NULL, NULL, 320
    UNION ALL SELECT 'accessories', 'weight_grams', 'Weight',
        'decimal', 'g', FALSE, TRUE, 20, 500, 330
    UNION ALL SELECT 'accessories', 'programmable_buttons',
        'Programmable button count', 'integer', 'buttons', FALSE, TRUE,
        0, 40, 340
    UNION ALL SELECT 'accessories', 'headset_style', 'Headset style',
        'option', NULL, FALSE, TRUE, NULL, NULL, 400
    UNION ALL SELECT 'accessories', 'has_microphone', 'Built-in microphone',
        'boolean', NULL, FALSE, TRUE, NULL, NULL, 410
    UNION ALL SELECT 'accessories', 'wireless_capable', 'Wireless capable',
        'boolean', NULL, FALSE, TRUE, NULL, NULL, 420
    UNION ALL SELECT 'accessories', 'enclosure_type', 'Acoustic enclosure',
        'option', NULL, FALSE, TRUE, NULL, NULL, 430
    UNION ALL SELECT 'accessories', 'frequency_min_hz',
        'Minimum frequency response', 'integer', 'Hz', FALSE, TRUE,
        1, 1000, 440
    UNION ALL SELECT 'accessories', 'frequency_max_hz',
        'Maximum frequency response', 'integer', 'Hz', FALSE, TRUE,
        1000, 200000, 450
    UNION ALL SELECT 'accessories', 'surround_sound', 'Surround sound',
        'boolean', NULL, FALSE, TRUE, NULL, NULL, 460
) d ON d.category_slug = c.slug
ON DUPLICATE KEY UPDATE
    display_name=VALUES(display_name), data_type=VALUES(data_type),
    unit=VALUES(unit), is_required=VALUES(is_required),
    is_filterable=VALUES(is_filterable),
    is_compatibility_field=VALUES(is_compatibility_field),
    minimum_value=VALUES(minimum_value), maximum_value=VALUES(maximum_value),
    sort_order=VALUES(sort_order), is_active=TRUE;

INSERT INTO specification_options
    (definition_id, value_code, display_value, sort_order, is_active)
SELECT sd.id, o.value_code, o.display_value, o.sort_order, TRUE
FROM categories c
INNER JOIN specification_definitions sd ON sd.category_id=c.id
INNER JOIN (
    SELECT 'accessories' category_slug, 'connectivity' definition_code,
        'wired' value_code, 'Wired' display_value, 10 sort_order
    UNION ALL SELECT 'accessories', 'connectivity', 'wireless', 'Wireless', 20
    UNION ALL SELECT 'accessories', 'connectivity', 'bluetooth', 'Bluetooth', 30
    UNION ALL SELECT 'accessories', 'panel_type', 'ips', 'IPS', 10
    UNION ALL SELECT 'accessories', 'panel_type', 'va', 'VA', 20
    UNION ALL SELECT 'accessories', 'panel_type', 'tn', 'TN', 30
    UNION ALL SELECT 'accessories', 'panel_type', 'oled', 'OLED', 40
    UNION ALL SELECT 'accessories', 'panel_type', 'qd_oled', 'QD-OLED', 50
    UNION ALL SELECT 'accessories', 'panel_type', 'mini_led', 'Mini-LED', 60
    UNION ALL SELECT 'accessories', 'panel_type', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'aspect_ratio', '16_9', '16:9', 10
    UNION ALL SELECT 'accessories', 'aspect_ratio', '16_10', '16:10', 20
    UNION ALL SELECT 'accessories', 'aspect_ratio', '21_9', '21:9', 30
    UNION ALL SELECT 'accessories', 'aspect_ratio', '32_9', '32:9', 40
    UNION ALL SELECT 'accessories', 'aspect_ratio', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'adaptive_sync', 'freesync', 'AMD FreeSync', 10
    UNION ALL SELECT 'accessories', 'adaptive_sync', 'gsync', 'NVIDIA G-SYNC', 20
    UNION ALL SELECT 'accessories', 'adaptive_sync', 'compatible', 'Adaptive-Sync compatible', 30
    UNION ALL SELECT 'accessories', 'adaptive_sync', 'multiple', 'Multiple standards', 40
    UNION ALL SELECT 'accessories', 'adaptive_sync', 'none', 'None', 50
    UNION ALL SELECT 'accessories', 'keyboard_size', 'full_size', 'Full-size', 10
    UNION ALL SELECT 'accessories', 'keyboard_size', 'compact_96', '96%', 20
    UNION ALL SELECT 'accessories', 'keyboard_size', 'tenkeyless', 'Tenkeyless', 30
    UNION ALL SELECT 'accessories', 'keyboard_size', 'compact_75', '75%', 40
    UNION ALL SELECT 'accessories', 'keyboard_size', 'compact_65', '65%', 50
    UNION ALL SELECT 'accessories', 'keyboard_size', 'compact_60', '60%', 60
    UNION ALL SELECT 'accessories', 'keyboard_size', 'compact', 'Compact', 70
    UNION ALL SELECT 'accessories', 'keyboard_size', 'ergonomic', 'Ergonomic', 80
    UNION ALL SELECT 'accessories', 'keyboard_size', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'switch_technology', 'mechanical', 'Mechanical', 10
    UNION ALL SELECT 'accessories', 'switch_technology', 'membrane', 'Membrane', 20
    UNION ALL SELECT 'accessories', 'switch_technology', 'optical', 'Optical', 30
    UNION ALL SELECT 'accessories', 'switch_technology', 'scissor', 'Scissor', 40
    UNION ALL SELECT 'accessories', 'switch_technology', 'hall_effect', 'Hall effect', 50
    UNION ALL SELECT 'accessories', 'switch_technology', 'hybrid', 'Hybrid', 60
    UNION ALL SELECT 'accessories', 'switch_technology', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'backlight_type', 'none', 'None', 10
    UNION ALL SELECT 'accessories', 'backlight_type', 'single_colour', 'Single colour', 20
    UNION ALL SELECT 'accessories', 'backlight_type', 'multicolour', 'Multicolour', 30
    UNION ALL SELECT 'accessories', 'backlight_type', 'rgb', 'RGB', 40
    UNION ALL SELECT 'accessories', 'tracking_method', 'optical', 'Optical', 10
    UNION ALL SELECT 'accessories', 'tracking_method', 'laser', 'Laser', 20
    UNION ALL SELECT 'accessories', 'tracking_method', 'trackball', 'Trackball', 30
    UNION ALL SELECT 'accessories', 'tracking_method', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'hand_orientation', 'right', 'Right-handed', 10
    UNION ALL SELECT 'accessories', 'hand_orientation', 'left', 'Left-handed', 20
    UNION ALL SELECT 'accessories', 'hand_orientation', 'ambidextrous', 'Ambidextrous', 30
    UNION ALL SELECT 'accessories', 'hand_orientation', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'headset_style', 'over_ear', 'Over-ear', 10
    UNION ALL SELECT 'accessories', 'headset_style', 'on_ear', 'On-ear', 20
    UNION ALL SELECT 'accessories', 'headset_style', 'in_ear', 'In-ear', 30
    UNION ALL SELECT 'accessories', 'headset_style', 'other', 'Other', 90
    UNION ALL SELECT 'accessories', 'enclosure_type', 'closed', 'Closed-back', 10
    UNION ALL SELECT 'accessories', 'enclosure_type', 'open', 'Open-back', 20
    UNION ALL SELECT 'accessories', 'enclosure_type', 'semi_open', 'Semi-open', 30
    UNION ALL SELECT 'accessories', 'enclosure_type', 'other', 'Other', 90
) o ON o.category_slug=c.slug AND o.definition_code=sd.code
ON DUPLICATE KEY UPDATE
    display_value=VALUES(display_value), sort_order=VALUES(sort_order),
    is_active=TRUE;

INSERT INTO product_tags (code, display_name, tag_type)
VALUES
    ('competitive_gaming', 'Competitive Gaming', 'intended_use'),
    ('visual_creative', 'Visual Creative Work', 'intended_use'),
    ('productivity', 'Productivity', 'intended_use'),
    ('portable', 'Portable and Compact', 'feature'),
    ('ergonomic', 'Ergonomic', 'feature'),
    ('accessibility', 'Accessibility', 'feature'),
    ('communication', 'Calls and Communication', 'intended_use'),
    ('music_creation', 'Music Creation', 'intended_use'),
    ('general', 'General Use', 'intended_use')
ON DUPLICATE KEY UPDATE
    display_name=VALUES(display_name), tag_type=VALUES(tag_type), is_active=TRUE;
