"""Offline constraint/repeatability evaluation using the cleaned Kaggle laptops.

There are no user relevance labels in the source dataset, so this script does
not report accuracy, precision, recall, or user satisfaction.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd

from hexbay_ai.recommenders.laptop import (
    ALGORITHM_VERSION,
    MAX_CANDIDATES,
    recommend_laptops,
)


SERVICE_ROOT = Path(__file__).resolve().parent
PROCESSED_DIR = SERVICE_ROOT / "data" / "processed"
INPUT_PATH = PROCESSED_DIR / "laptops_clean.csv"
JSON_REPORT_PATH = PROCESSED_DIR / "laptop_recommender_evaluation.json"
MARKDOWN_REPORT_PATH = PROCESSED_DIR / "laptop_recommender_evaluation.md"
REFERENCE_PRICE_SCALE = 100.0


def _candidates(frame: pd.DataFrame) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    for product_id, row in enumerate(frame.itertuples(index=False), start=1):
        results.append(
            {
                "product_id": product_id,
                "name": f"{row.brand} {row.laptop_type}",
                "brand": str(row.brand),
                # Evaluation-only monotonic scaling. This is explicitly not an
                # exchange-rate conversion or a current Sri Lankan price.
                "price_lkr": float(row.reference_price) * REFERENCE_PRICE_SCALE,
                "ram_gb": float(row.ram_gb),
                "storage_gb": float(row.storage_total_gb),
                "cpu": str(row.cpu),
                "gpu": str(row.gpu),
                "screen_size_inches": float(row.screen_size_inches),
                "weight_kg": (
                    None if pd.isna(row.weight_kg) else float(row.weight_kg)
                ),
                "stock_quantity": 1,
                "rating_count": 0,
                "eligible": True,
                "tags": [str(row.laptop_type)],
            }
        )
    return results


def _requirements(frame: pd.DataFrame) -> list[tuple[str, dict[str, Any]]]:
    scaled_prices = frame["reference_price"] * REFERENCE_PRICE_SCALE
    return [
        (
            "general_mid_budget",
            {
                "max_budget_lkr": float(scaled_prices.quantile(0.50)),
                "intended_use": "general",
                "minimum_ram_gb": 8,
                "minimum_storage_gb": 256,
            },
        ),
        (
            "gaming_high_budget",
            {
                "max_budget_lkr": float(scaled_prices.quantile(0.90)),
                "intended_use": "gaming",
                "minimum_ram_gb": 16,
                "minimum_storage_gb": 512,
                "require_dedicated_gpu": True,
            },
        ),
        (
            "portable_study",
            {
                "max_budget_lkr": float(scaled_prices.quantile(0.55)),
                "intended_use": "study",
                "minimum_ram_gb": 8,
                "minimum_storage_gb": 128,
                "maximum_screen_size_inches": 14,
                "preferred_screen_size_inches": 13.3,
            },
        ),
        (
            "programming",
            {
                "max_budget_lkr": float(scaled_prices.quantile(0.75)),
                "intended_use": "programming",
                "minimum_ram_gb": 16,
                "minimum_storage_gb": 512,
            },
        ),
    ]


def _verify_constraints(
    recommendations: list[dict[str, Any]],
    candidates_by_id: dict[int, dict[str, Any]],
    requirements: dict[str, Any],
) -> list[str]:
    violations: list[str] = []
    for recommendation in recommendations:
        candidate = candidates_by_id[recommendation["product_id"]]
        if candidate["price_lkr"] > requirements["max_budget_lkr"]:
            violations.append(f"{candidate['product_id']}:over_budget")
        if candidate["ram_gb"] < requirements.get("minimum_ram_gb", 0):
            violations.append(f"{candidate['product_id']}:insufficient_ram")
        if candidate["storage_gb"] < requirements.get("minimum_storage_gb", 0):
            violations.append(f"{candidate['product_id']}:insufficient_storage")
        maximum_screen = requirements.get("maximum_screen_size_inches")
        if maximum_screen and candidate["screen_size_inches"] > maximum_screen:
            violations.append(f"{candidate['product_id']}:screen_above_maximum")
    return violations


def evaluate() -> dict[str, Any]:
    if not INPUT_PATH.exists():
        raise FileNotFoundError(
            "Run dataset_pipeline.py before evaluating the laptop recommender."
        )
    source_frame = pd.read_csv(INPUT_PATH)
    if len(source_frame) > MAX_CANDIDATES:
        sample_indexes = np.linspace(
            0,
            len(source_frame) - 1,
            MAX_CANDIDATES,
            dtype=int,
        )
        frame = source_frame.iloc[sample_indexes].reset_index(drop=True)
    else:
        frame = source_frame
    candidates = _candidates(frame)
    candidates_by_id = {item["product_id"]: item for item in candidates}
    scenarios: list[dict[str, Any]] = []

    for name, requirements in _requirements(frame):
        request = {
            "requirements": requirements,
            "candidates": candidates,
            "limit": 5,
        }
        first = recommend_laptops(request)
        second = recommend_laptops(request)
        first_ids = [
            item["product_id"] for item in first["recommendations"]
        ]
        second_ids = [
            item["product_id"] for item in second["recommendations"]
        ]
        violations = _verify_constraints(
            first["recommendations"], candidates_by_id, requirements
        )
        scenarios.append(
            {
                "name": name,
                "eligible_candidates": first["eligible_candidate_count"],
                "returned_recommendations": len(first["recommendations"]),
                "returned_product_ids": first_ids,
                "repeatable": first_ids == second_ids,
                "hard_constraint_violations": violations,
                "filter_summary": first["filter_summary"],
            }
        )

    report = {
        "algorithm_version": ALGORITHM_VERSION,
        "generated_at_utc": datetime.now(timezone.utc)
        .replace(microsecond=0)
        .isoformat(),
        "source_records": int(len(source_frame)),
        "evaluation_candidates": int(len(frame)),
        "scenario_count": len(scenarios),
        "scenarios_with_results": sum(
            bool(item["returned_recommendations"]) for item in scenarios
        ),
        "repeatable_scenarios": sum(bool(item["repeatable"]) for item in scenarios),
        "hard_constraint_violation_count": sum(
            len(item["hard_constraint_violations"]) for item in scenarios
        ),
        "reference_price_handling": {
            "scale_multiplier": REFERENCE_PRICE_SCALE,
            "warning": (
                "Evaluation-only monotonic scaling; not an exchange-rate "
                "conversion and not a current LKR market price."
            ),
        },
        "limitations": [
            "The Kaggle data has no user relevance labels.",
            "This report does not measure accuracy, precision, recall, or satisfaction.",
            "Live recommendations must use eligible MySQL marketplace candidates.",
        ],
        "scenarios": scenarios,
    }
    JSON_REPORT_PATH.write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    lines = [
        "# HEXBAY Laptop Recommender Offline Evaluation",
        "",
        f"- Algorithm: `{ALGORITHM_VERSION}`",
        f"- Cleaned reference laptops: **{len(source_frame):,}**",
        f"- Deterministic evaluation sample: **{len(frame):,}**",
        f"- Scenarios: **{len(scenarios)}**",
        f"- Scenarios with results: **{report['scenarios_with_results']}**",
        f"- Repeatable scenarios: **{report['repeatable_scenarios']}**",
        (
            "- Hard-constraint violations in returned results: "
            f"**{report['hard_constraint_violation_count']}**"
        ),
        "",
        "The source prices use an evaluation-only monotonic scale. They are not "
        "an exchange-rate conversion and are not current Sri Lankan prices.",
        "",
        "| Scenario | Eligible | Returned | Repeatable | Violations |",
        "|---|---:|---:|:---:|---:|",
    ]
    for scenario in scenarios:
        lines.append(
            f"| {scenario['name']} | {scenario['eligible_candidates']} | "
            f"{scenario['returned_recommendations']} | "
            f"{'Yes' if scenario['repeatable'] else 'No'} | "
            f"{len(scenario['hard_constraint_violations'])} |"
        )
    lines.extend(
        [
            "",
            "## Limitations",
            "",
            "- There are no user relevance labels in the Kaggle dataset.",
            "- No accuracy, precision, recall, or satisfaction claim is made.",
            "- Live recommendations must use eligible candidates from HEXBAY MySQL.",
            "",
        ]
    )
    MARKDOWN_REPORT_PATH.write_text("\n".join(lines), encoding="utf-8")
    return report


def main() -> int:
    report = evaluate()
    print(
        "Laptop recommender evaluation complete: "
        f"{report['scenario_count']} scenarios, "
        f"{report['hard_constraint_violation_count']} constraint violations."
    )
    print(f"Report: {MARKDOWN_REPORT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
