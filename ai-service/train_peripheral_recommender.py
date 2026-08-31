"""Train and evaluate HEXBAY's experimental hybrid peripheral models."""

from __future__ import annotations

import json
import hashlib
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
from sklearn.metrics import accuracy_score, f1_score, ndcg_score
from sklearn.model_selection import StratifiedKFold, train_test_split

from hexbay_ai.recommenders.peripheral import (
    ALGORITHM_VERSION,
    SUPPORTED_PROFILES,
    feature_frame,
    hybrid_score_components,
    rank_peripherals,
    train_bundle,
)


SERVICE_ROOT = Path(__file__).resolve().parent
DATA_DIR = SERVICE_ROOT / "data" / "processed" / "peripherals"
MODEL_DIR = SERVICE_ROOT / "models" / "peripherals"
REPORT_JSON = DATA_DIR / "peripheral_model_evaluation.json"
REPORT_MD = DATA_DIR / "peripheral_model_evaluation.md"
RANDOM_STATE = 42
CATEGORY_FILES = {
    "monitor": "monitor_candidates_clean.csv",
    "keyboard": "keyboard_candidates_clean.csv",
    "mouse": "mouse_candidates_clean.csv",
    "headset": "headset_candidates_clean.csv",
}


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def evaluate_category(category: str, frame: pd.DataFrame) -> tuple[dict[str, Any], dict[str, Any]]:
    cross_validation = StratifiedKFold(
        n_splits=5, shuffle=True, random_state=RANDOM_STATE
    )
    cv_accuracy: list[float] = []
    cv_macro_f1: list[float] = []
    for train_indexes, validation_indexes in cross_validation.split(
        frame, frame["primary_use_profile"]
    ):
        fold_train = frame.iloc[train_indexes]
        fold_validation = frame.iloc[validation_indexes]
        fold_bundle = train_bundle(fold_train, category)
        fold_matrix = fold_bundle["preprocessor"].transform(
            feature_frame(fold_validation, category)
        )
        fold_prediction = fold_bundle["classifier"].predict(fold_matrix)
        fold_labels = fold_validation["primary_use_profile"].astype(str)
        cv_accuracy.append(float(accuracy_score(fold_labels, fold_prediction)))
        cv_macro_f1.append(float(f1_score(fold_labels, fold_prediction, average="macro")))

    train, test = train_test_split(
        frame,
        test_size=0.20,
        random_state=RANDOM_STATE,
        stratify=frame["primary_use_profile"],
    )
    evaluation_bundle = train_bundle(train, category)
    prepared_test = evaluation_bundle["preprocessor"].transform(
        feature_frame(test, category)
    )
    predicted = evaluation_bundle["classifier"].predict(prepared_test)
    labels = test["primary_use_profile"].astype(str).to_numpy()
    ranking_scenarios: list[dict[str, Any]] = []
    for profile in SUPPORTED_PROFILES[category]:
        components = hybrid_score_components(evaluation_bundle, test, profile)
        relevance = (labels == profile).astype(int)
        scores = components["score"].to_numpy()
        order = np.argsort(-scores, kind="stable")[:5]
        precision_at_5 = float(relevance[order].mean())
        ndcg_at_5 = float(ndcg_score([relevance], [scores], k=5))
        first = rank_peripherals(
            evaluation_bundle, test, profile, limit=5, allow_unverified=True
        )
        second = rank_peripherals(
            evaluation_bundle, test, profile, limit=5, allow_unverified=True
        )
        first_ids = [item["identity_key"] for item in first["recommendations"]]
        second_ids = [item["identity_key"] for item in second["recommendations"]]
        ranking_scenarios.append(
            {
                "profile": profile,
                "weak_precision_at_5": round(precision_at_5, 4),
                "weak_ndcg_at_5": round(ndcg_at_5, 4),
                "repeatable": first_ids == second_ids,
                "returned": len(first_ids),
            }
        )
    gated = rank_peripherals(
        evaluation_bundle,
        test,
        SUPPORTED_PROFILES[category][0],
        limit=5,
        allow_unverified=False,
    )
    metrics = {
        "source_rows": int(len(frame)),
        "training_rows": int(len(train)),
        "holdout_rows": int(len(test)),
        "weak_label_accuracy": round(float(accuracy_score(labels, predicted)), 4),
        "weak_label_macro_f1": round(float(f1_score(labels, predicted, average="macro")), 4),
        "five_fold_weak_accuracy_mean": round(float(np.mean(cv_accuracy)), 4),
        "five_fold_weak_accuracy_std": round(float(np.std(cv_accuracy)), 4),
        "five_fold_weak_macro_f1_mean": round(float(np.mean(cv_macro_f1)), 4),
        "five_fold_weak_macro_f1_std": round(float(np.std(cv_macro_f1)), 4),
        "mean_weak_precision_at_5": round(float(np.mean([item["weak_precision_at_5"] for item in ranking_scenarios])), 4),
        "mean_weak_ndcg_at_5": round(float(np.mean([item["weak_ndcg_at_5"] for item in ranking_scenarios])), 4),
        "repeatable_scenarios": sum(item["repeatable"] for item in ranking_scenarios),
        "scenario_count": len(ranking_scenarios),
        "production_gate_passed": gated["recommendations"] == [] and gated["blocked_unverified_count"] == len(test),
        "profiles": ranking_scenarios,
    }
    final_bundle = train_bundle(frame, category)
    return metrics, final_bundle


def train_and_evaluate() -> dict[str, Any]:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    results: dict[str, Any] = {}
    for category, filename in CATEGORY_FILES.items():
        frame = pd.read_csv(DATA_DIR / filename)
        metrics, bundle = evaluate_category(category, frame)
        artifact_path = MODEL_DIR / f"{category}_{ALGORITHM_VERSION}.joblib"
        joblib.dump(bundle, artifact_path, compress=3)
        reloaded = joblib.load(artifact_path)
        reload_smoke = rank_peripherals(
            reloaded,
            frame.head(25),
            SUPPORTED_PROFILES[category][0],
            limit=3,
            allow_unverified=True,
        )
        metrics["artifact"] = artifact_path.name
        metrics["artifact_bytes"] = artifact_path.stat().st_size
        metrics["artifact_sha256"] = sha256_file(artifact_path)
        metrics["artifact_reload_passed"] = (
            reloaded["algorithm_version"] == ALGORITHM_VERSION
            and len(reload_smoke["recommendations"]) == 3
        )
        results[category] = metrics
    report = {
        "algorithm_version": ALGORITHM_VERSION,
        "generated_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "production_ready": False,
        "training_authority": "reference_only_weak_labels",
        "categories": results,
        "overall": {
            "source_rows": sum(item["source_rows"] for item in results.values()),
            "mean_weak_label_accuracy": round(float(np.mean([item["weak_label_accuracy"] for item in results.values()])), 4),
            "mean_weak_label_macro_f1": round(float(np.mean([item["weak_label_macro_f1"] for item in results.values()])), 4),
            "mean_five_fold_weak_accuracy": round(float(np.mean([item["five_fold_weak_accuracy_mean"] for item in results.values()])), 4),
            "mean_five_fold_weak_macro_f1": round(float(np.mean([item["five_fold_weak_macro_f1_mean"] for item in results.values()])), 4),
            "mean_weak_precision_at_5": round(float(np.mean([item["mean_weak_precision_at_5"] for item in results.values()])), 4),
            "mean_weak_ndcg_at_5": round(float(np.mean([item["mean_weak_ndcg_at_5"] for item in results.values()])), 4),
            "all_scenarios_repeatable": all(item["repeatable_scenarios"] == item["scenario_count"] for item in results.values()),
            "all_production_gates_passed": all(item["production_gate_passed"] for item in results.values()),
            "all_artifacts_reload_passed": all(item["artifact_reload_passed"] for item in results.values()),
        },
        "limitations": [
            "Purpose labels are deterministic weak labels, not customer relevance judgements.",
            "Accuracy and ranking metrics measure agreement with those weak labels only.",
            "All source products remain unverified and are blocked in production mode.",
            "Price, stock, warranty, delivery, and seller trust are intentionally absent from model training.",
            "Joblib artifacts must be loaded only from trusted local builds and checked against their recorded SHA-256 hashes.",
        ],
    }
    REPORT_JSON.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    lines = [
        "# HEXBAY Peripheral Hybrid Model Evaluation",
        "",
        f"- Algorithm: `{ALGORITHM_VERSION}`",
        f"- Reference candidates: **{report['overall']['source_rows']:,}**",
        f"- Production ready: **No**",
        f"- Mean weak-label accuracy: **{report['overall']['mean_weak_label_accuracy']:.4f}**",
        f"- Mean weak-label macro F1: **{report['overall']['mean_weak_label_macro_f1']:.4f}**",
        f"- Mean five-fold weak-label accuracy: **{report['overall']['mean_five_fold_weak_accuracy']:.4f}**",
        f"- Mean five-fold weak-label macro F1: **{report['overall']['mean_five_fold_weak_macro_f1']:.4f}**",
        f"- Mean weak precision@5: **{report['overall']['mean_weak_precision_at_5']:.4f}**",
        f"- Mean weak NDCG@5: **{report['overall']['mean_weak_ndcg_at_5']:.4f}**",
        f"- Deterministic ranking: **{'Pass' if report['overall']['all_scenarios_repeatable'] else 'Fail'}**",
        f"- Unverified-data production gate: **{'Pass' if report['overall']['all_production_gates_passed'] else 'Fail'}**",
        f"- Artifact hash/reload smoke tests: **{'Pass' if report['overall']['all_artifacts_reload_passed'] else 'Fail'}**",
        "",
        "| Category | Rows | Holdout accuracy | 5-fold accuracy | 5-fold macro F1 | Precision@5 | NDCG@5 | Gate |",
        "|---|---:|---:|---:|---:|---:|---:|:---:|",
    ]
    for category, item in results.items():
        lines.append(
            f"| {category.title()} | {item['source_rows']:,} | "
            f"{item['weak_label_accuracy']:.4f} | {item['five_fold_weak_accuracy_mean']:.4f} | "
            f"{item['five_fold_weak_macro_f1_mean']:.4f} | "
            f"{item['mean_weak_precision_at_5']:.4f} | {item['mean_weak_ndcg_at_5']:.4f} | "
            f"{'Pass' if item['production_gate_passed'] else 'Fail'} |"
        )
    lines.extend(
        [
            "",
            "These scores measure agreement with deterministic candidate labels, not real customer satisfaction or objective product superiority.",
            "",
            "## Limitations",
            "",
            *[f"- {item}" for item in report["limitations"]],
            "",
        ]
    )
    REPORT_MD.write_text("\n".join(lines), encoding="utf-8")
    return report


def main() -> int:
    report = train_and_evaluate()
    print(json.dumps(report["overall"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
