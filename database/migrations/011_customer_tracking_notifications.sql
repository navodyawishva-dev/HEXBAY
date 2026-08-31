USE hexbay;

-- Supports fast, ownership-scoped deep-link resolution for order and
-- seller-delivery notifications as the notification history grows.
ALTER TABLE notifications
    ADD INDEX idx_notifications_related
        (related_resource_type, related_resource_id, user_id);
