import pandas as pd

from app import create_app

from hexbay_ai.recommenders.peripheral import (
    SCORE_WEIGHTS,
    rank_peripherals,
    rule_score,
    train_bundle,
)


def monitor_frame() -> pd.DataFrame:
    rows = []
    profiles = ["general", "productivity", "visual_creative", "gaming", "competitive_gaming"]
    for index in range(25):
        profile = profiles[index % len(profiles)]
        competitive = profile == "competitive_gaming"
        gaming = profile == "gaming"
        creative = profile == "visual_creative"
        productivity = profile == "productivity"
        rows.append(
            {
                "source_record_id": f"source-{index}",
                "identity_key": f"monitor:brand:model-{index}",
                "accessory_type": "monitor",
                "raw_name": f"Brand Model {index}",
                "brand": "Brand" if index % 2 else "Maker",
                "model": f"Model {index}",
                "primary_use_profile": profile,
                "recommendation_eligible": False,
                "review_status": "needs_review",
                "completeness_score": 100,
                "source_variant_count": 1,
                "screen_size_inches": 32 if productivity or creative else 24,
                "resolution_width_pixels": 3840 if creative else (3440 if productivity else 1920),
                "resolution_height_pixels": 2160 if creative else (1440 if productivity else 1080),
                "refresh_rate_hz": 240 if competitive else (165 if gaming else 60),
                "response_time_ms": 1 if competitive or gaming else 5,
                "panel_type": "OLED" if creative else "IPS",
                "aspect_ratio": "21_9" if productivity else "16_9",
            }
        )
    return pd.DataFrame(rows)


def test_competitive_monitor_rule_rewards_refresh_and_response_time() -> None:
    fast = {
        "screen_size_inches": 24,
        "resolution_height_pixels": 1080,
        "refresh_rate_hz": 240,
        "response_time_ms": 1,
    }
    slow = {**fast, "refresh_rate_hz": 60, "response_time_ms": 8}
    assert rule_score("monitor", fast, "competitive_gaming") > rule_score(
        "monitor", slow, "competitive_gaming"
    )


def test_unverified_candidates_are_blocked_by_default() -> None:
    frame = monitor_frame()
    bundle = train_bundle(frame, "monitor")

    result = rank_peripherals(bundle, frame, "gaming")

    assert result["recommendations"] == []
    assert result["blocked_unverified_count"] == len(frame)


def test_experimental_ranking_is_explainable_and_repeatable() -> None:
    frame = monitor_frame()
    bundle = train_bundle(frame, "monitor")

    first = rank_peripherals(
        bundle, frame, "competitive_gaming", limit=5, allow_unverified=True
    )
    second = rank_peripherals(
        bundle, frame, "competitive_gaming", limit=5, allow_unverified=True
    )

    assert [item["identity_key"] for item in first["recommendations"]] == [
        item["identity_key"] for item in second["recommendations"]
    ]
    assert set(first["recommendations"][0]["score_breakdown"]) == set(SCORE_WEIGHTS)
    assert "price" not in first["recommendations"][0]
    assert "Requires trusted specification" in first["recommendations"][0]["reasons"][-1]


def test_internal_endpoint_is_authenticated_and_explicitly_gated() -> None:
    expected = {
        "algorithm_version": "peripheral-hybrid-v1.0.0",
        "category": "mouse",
        "profile": "gaming",
        "mode": "experimental",
        "recommendations": [{"identity_key": "product:7", "score": 91.0}],
    }
    app = create_app({
        "TESTING": True,
        "INTERNAL_SERVICE_SECRET": "test-internal-secret",
        "ALLOW_EXPERIMENTAL_PERIPHERALS": True,
        "PERIPHERAL_RANKER": lambda payload: expected,
    })
    client = app.test_client()
    payload = {
        "category": "mouse",
        "profile": "gaming",
        "limit": 5,
        "allow_unverified": True,
        "candidates": [{"identity_key": "product:7", "accessory_type": "mouse"}],
    }
    assert client.post(
        "/internal/recommend/peripherals", json=payload
    ).status_code == 401
    response = client.post(
        "/internal/recommend/peripherals",
        json=payload,
        headers={"X-Hexbay-Internal-Secret": "test-internal-secret"},
    )
    assert response.status_code == 200
    assert response.get_json()["data"] == expected
