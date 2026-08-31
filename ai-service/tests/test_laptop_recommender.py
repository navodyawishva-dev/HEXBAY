import pytest

from app import create_app
from hexbay_ai.recommenders.laptop import (
    ALGORITHM_VERSION,
    RecommendationInputError,
    recommend_laptops,
)


def candidate(
    product_id,
    *,
    name,
    brand,
    price,
    ram,
    storage,
    cpu,
    gpu,
    stock=3,
    screen=15.6,
    weight=2.0,
    rating=4.2,
    rating_count=12,
    vendor_rating=4.3,
    eligible=True,
    tags=None,
):
    return {
        "product_id": product_id,
        "listing_id": product_id + 100,
        "name": name,
        "brand": brand,
        "price_lkr": price,
        "ram_gb": ram,
        "storage_gb": storage,
        "cpu": cpu,
        "gpu": gpu,
        "stock_quantity": stock,
        "screen_size_inches": screen,
        "weight_kg": weight,
        "rating_average": rating,
        "rating_count": rating_count,
        "vendor_rating": vendor_rating,
        "eligible": eligible,
        "tags": tags or ["laptop"],
    }


def gaming_payload():
    return {
        "requirements": {
            "max_budget_lkr": 400000,
            "intended_use": "gaming",
            "minimum_ram_gb": 16,
            "minimum_storage_gb": 512,
            "require_dedicated_gpu": True,
            "preferred_brands": ["ASUS"],
        },
        "candidates": [
            candidate(
                1,
                name="ASUS Gaming Pro",
                brand="ASUS",
                price=360000,
                ram=32,
                storage=1024,
                cpu="Intel Core i7",
                gpu="NVIDIA RTX 4070",
                stock=5,
                tags=["laptop", "gaming"],
            ),
            candidate(
                2,
                name="Office Light",
                brand="Other",
                price=180000,
                ram=16,
                storage=512,
                cpu="Intel Core i5",
                gpu="Intel Iris Xe integrated graphics",
                stock=8,
            ),
            candidate(
                3,
                name="Over Budget Gaming",
                brand="ASUS",
                price=480000,
                ram=32,
                storage=1024,
                cpu="Intel Core i9",
                gpu="NVIDIA RTX 4080",
                tags=["laptop", "gaming"],
            ),
        ],
        "limit": 3,
    }


def test_hard_filters_run_before_ranking_and_reasons_are_explainable():
    result = recommend_laptops(gaming_payload())

    assert result["algorithm_version"] == ALGORITHM_VERSION
    assert result["eligible_candidate_count"] == 1
    assert result["recommendations"][0]["product_id"] == 1
    assert result["recommendations"][0]["score"] > 0
    assert len(result["recommendations"][0]["reasons"]) >= 4
    assert set(result["recommendations"][0]["score_breakdown"]) == {
        "content_similarity",
        "specification_headroom",
        "price_fit",
        "preference_fit",
        "rating_confidence",
        "vendor_reliability",
        "availability",
    }
    filtered = {
        item["product_id"]: set(item["reason_codes"]) for item in result["filtered_out"]
    }
    assert "dedicated_gpu_required" in filtered[2]
    assert "over_budget" in filtered[3]


def test_no_match_returns_controlled_relaxation_suggestions():
    payload = gaming_payload()
    payload["requirements"]["max_budget_lkr"] = 100000

    result = recommend_laptops(payload)

    assert result["recommendations"] == []
    assert result["filter_summary"]["over_budget"] == 3
    assert "Increase the maximum budget." in result["relaxation_suggestions"]


def test_creator_laptop_is_separated_from_strict_gaming_results():
    payload = gaming_payload()
    payload["candidates"].append(
        candidate(
            4,
            name="ASUS Vivobook 16X",
            brand="ASUS",
            price=350000,
            ram=16,
            storage=1024,
            cpu="Intel Core i7-13700H",
            gpu="NVIDIA GeForce RTX 3050",
            tags=["laptop", "content_creation", "programming"],
        )
    )

    result = recommend_laptops(payload)

    assert [item["product_id"] for item in result["recommendations"]] == [1]
    assert result["gaming_capable_alternative_count"] == 1
    assert result["gaming_capable_alternatives"][0]["product_id"] == 4
    assert "Gaming-capable hardware" in (
        result["gaming_capable_alternatives"][0]["reasons"][0]
    )
    filtered = {
        item["product_id"]: set(item["reason_codes"])
        for item in result["filtered_out"]
    }
    assert "gaming_classification_required" in filtered[4]


def test_duplicate_product_ids_are_rejected():
    payload = gaming_payload()
    payload["candidates"].append(payload["candidates"][0].copy())

    with pytest.raises(RecommendationInputError) as error:
        recommend_laptops(payload)

    assert "candidates.3.product_id" in error.value.errors


def test_price_and_ram_ranges_filter_both_ends_without_a_use_case_filter():
    payload = {
        "requirements": {
            "minimum_budget_lkr": 200000,
            "max_budget_lkr": 350000,
            "intended_use": "any",
            "minimum_ram_gb": 8,
            "maximum_ram_gb": 16,
        },
        "candidates": [
            candidate(10, name="Below range", brand="A", price=180000, ram=8,
                      storage=512, cpu="Intel Core i5", gpu="Integrated"),
            candidate(11, name="Everyday match", brand="B", price=225000, ram=8,
                      storage=512, cpu="Intel Core i5", gpu="Integrated"),
            candidate(12, name="Gaming match", brand="C", price=340000, ram=16,
                      storage=1024, cpu="AMD Ryzen 7", gpu="NVIDIA RTX 4060"),
            candidate(13, name="Too much RAM", brand="D", price=300000, ram=32,
                      storage=1024, cpu="Intel Core i7", gpu="NVIDIA RTX 4070"),
        ],
        "limit": 12,
    }

    result = recommend_laptops(payload)

    assert result["eligible_candidate_count"] == 2
    assert {item["product_id"] for item in result["recommendations"]} == {11, 12}
    filtered = {
        item["product_id"]: set(item["reason_codes"])
        for item in result["filtered_out"]
    }
    assert "below_budget_range" in filtered[10]
    assert "ram_above_maximum" in filtered[13]
    assert all(
        "no use-case category was excluded" in item["reasons"][2]
        for item in result["recommendations"]
    )


def test_internal_endpoint_requires_secret_and_returns_ranked_result():
    app = create_app(
        {
            "TESTING": True,
            "INTERNAL_SERVICE_SECRET": "test-internal-secret",
        }
    )
    client = app.test_client()

    unauthenticated = client.post(
        "/internal/recommend/laptops",
        json=gaming_payload(),
    )
    assert unauthenticated.status_code == 401

    response = client.post(
        "/internal/recommend/laptops",
        json=gaming_payload(),
        headers={"X-Hexbay-Internal-Secret": "test-internal-secret"},
    )

    assert response.status_code == 200
    payload = response.get_json()
    assert payload["success"] is True
    assert payload["data"]["recommendations"][0]["product_id"] == 1


def test_invalid_request_returns_422_envelope():
    app = create_app(
        {
            "TESTING": True,
            "INTERNAL_SERVICE_SECRET": "test-internal-secret",
        }
    )
    client = app.test_client()

    response = client.post(
        "/internal/recommend/laptops",
        json={"requirements": {}, "candidates": []},
        headers={"X-Hexbay-Internal-Secret": "test-internal-secret"},
    )

    assert response.status_code == 422
    payload = response.get_json()
    assert payload["success"] is False
    assert "candidates" in payload["errors"]
