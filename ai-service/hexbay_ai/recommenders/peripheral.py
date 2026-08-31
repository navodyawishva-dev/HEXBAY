"""Experimental hybrid peripheral ranking for HEXBAY.

Models trained from the Step 2 discovery catalogue learn weak purpose labels,
not customer preference truth. Production ranking is gated to explicitly
verified candidates; unverified data is available only in experimental mode.
"""

from __future__ import annotations

import math
from typing import Any, Mapping

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics.pairwise import cosine_similarity
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler


ALGORITHM_VERSION = "peripheral-hybrid-v1.0.0"
SCORE_WEIGHTS = {
    "rule_fit": 0.45,
    "learned_purpose_probability": 0.30,
    "profile_similarity": 0.20,
    "data_completeness": 0.05,
}
SUPPORTED_PROFILES = {
    "monitor": (
        "general", "productivity", "visual_creative", "gaming",
        "competitive_gaming",
    ),
    "keyboard": ("productivity", "portable", "ergonomic", "gaming"),
    "mouse": ("productivity", "portable", "accessibility", "gaming"),
    "headset": ("communication", "music_creation", "portable", "gaming"),
}
FEATURES = {
    "monitor": {
        "numeric": (
            "completeness_score", "source_variant_count", "screen_size_inches",
            "resolution_width_pixels", "resolution_height_pixels",
            "refresh_rate_hz", "response_time_ms",
        ),
        "categorical": ("brand", "panel_type", "aspect_ratio"),
        "text": ("brand", "model"),
    },
    "keyboard": {
        "numeric": ("completeness_score", "source_variant_count"),
        "categorical": (
            "brand", "keyboard_size", "switch_technology", "backlight_type",
            "connectivity",
        ),
        "text": ("brand", "model", "keyboard_style_raw", "switch_model"),
    },
    "mouse": {
        "numeric": ("completeness_score", "source_variant_count", "max_dpi"),
        "categorical": ("brand", "tracking_method", "connectivity", "hand_orientation"),
        "text": ("brand", "model"),
    },
    "headset": {
        "numeric": ("completeness_score", "source_variant_count"),
        "categorical": (
            "brand", "headset_style", "has_microphone", "wireless_capable",
            "enclosure_type",
        ),
        "text": ("brand", "model"),
    },
}


def _clamp(value: float) -> float:
    return max(0.0, min(1.0, float(value)))


def _number(row: Mapping[str, Any], field: str, default: float = 0.0) -> float:
    value = row.get(field)
    if value is None or pd.isna(value):
        return default
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        return default
    return parsed if math.isfinite(parsed) else default


def _truthy(value: Any) -> bool:
    if isinstance(value, (bool, np.bool_)):
        return bool(value)
    return str(value).strip().casefold() in {"true", "1", "yes", "verified"}


def _contains(value: Any, token: str) -> bool:
    return token in str(value or "").casefold().split(";")


def _average(*values: float) -> float:
    return sum(values) / len(values) if values else 0.0


def _monitor_rule(row: Mapping[str, Any], profile: str) -> float:
    size = _number(row, "screen_size_inches")
    width = _number(row, "resolution_width_pixels")
    height = _number(row, "resolution_height_pixels")
    refresh = _number(row, "refresh_rate_hz", 60)
    response = _number(row, "response_time_ms", 8)
    panel = str(row.get("panel_type") or "").casefold()
    if profile == "general":
        return _average(
            1.0 if 22 <= size <= 32 else 0.55,
            _clamp(height / 1080),
            _clamp(refresh / 75),
        )
    if profile == "productivity":
        return _average(
            _clamp((size - 20) / 14),
            _clamp(width / 3440),
            1.0 if str(row.get("aspect_ratio")) in {"21_9", "32_9"} else 0.65,
        )
    if profile == "visual_creative":
        return _average(
            1.0 if panel in {"ips", "oled", "qd_oled", "mini_led"} else 0.35,
            _clamp(width / 3840),
            _clamp(height / 2160),
        )
    if profile == "gaming":
        return _average(
            _clamp(refresh / 180),
            _clamp(4 / max(response, 0.1)),
            _clamp(height / 1440),
        )
    return _average(
        _clamp((refresh - 75) / 285),
        _clamp(2 / max(response, 0.1)),
        1.0 if 1080 <= height <= 1440 else 0.55,
    )


def _keyboard_rule(row: Mapping[str, Any], profile: str) -> float:
    size = str(row.get("keyboard_size") or "")
    switch = str(row.get("switch_technology") or "")
    connection = row.get("connectivity")
    if profile == "productivity":
        return _average(
            1.0 if size in {"full_size", "compact_96", "ergonomic"} else 0.55,
            1.0 if connection else 0.35,
            1.0 if switch else 0.50,
        )
    if profile == "portable":
        return _average(
            1.0 if size in {"compact", "compact_60", "compact_65", "compact_75", "tenkeyless"} else 0.35,
            1.0 if _contains(connection, "wireless") or _contains(connection, "bluetooth") else 0.45,
        )
    if profile == "ergonomic":
        return 1.0 if size == "ergonomic" else 0.20
    return _average(
        1.0 if switch in {"mechanical", "optical", "hall_effect"} else 0.35,
        1.0 if str(row.get("backlight_type")) == "rgb" else 0.55,
        1.0 if connection else 0.40,
    )


def _mouse_rule(row: Mapping[str, Any], profile: str) -> float:
    dpi = _number(row, "max_dpi")
    connection = row.get("connectivity")
    hand = str(row.get("hand_orientation") or "")
    tracking = str(row.get("tracking_method") or "")
    if profile == "productivity":
        return _average(
            1.0 if tracking in {"optical", "laser", "trackball"} else 0.45,
            1.0 if connection else 0.35,
            1.0 if hand else 0.50,
        )
    if profile == "portable":
        return 1.0 if _contains(connection, "wireless") or _contains(connection, "bluetooth") else 0.25
    if profile == "accessibility":
        return 1.0 if hand in {"left", "ambidextrous"} else 0.20
    return _average(
        _clamp(dpi / 16000),
        1.0 if tracking == "optical" else 0.45,
        1.0 if connection else 0.40,
    )


def _headset_rule(row: Mapping[str, Any], profile: str) -> float:
    microphone = _truthy(row.get("has_microphone"))
    wireless = _truthy(row.get("wireless_capable"))
    enclosure = str(row.get("enclosure_type") or "")
    style = str(row.get("headset_style") or "")
    if profile == "communication":
        return _average(1.0 if microphone else 0.0, 1.0 if wireless else 0.60)
    if profile == "music_creation":
        return _average(
            1.0 if enclosure in {"open", "semi_open"} else 0.40,
            1.0 if style == "over_ear" else 0.55,
            0.70 if not wireless else 0.45,
        )
    if profile == "portable":
        return _average(1.0 if wireless else 0.15, 1.0 if style in {"on_ear", "in_ear"} else 0.55)
    return _average(
        1.0 if microphone else 0.0,
        1.0 if enclosure == "closed" else 0.40,
        1.0 if style == "over_ear" else 0.55,
    )


RULES = {
    "monitor": _monitor_rule,
    "keyboard": _keyboard_rule,
    "mouse": _mouse_rule,
    "headset": _headset_rule,
}


def rule_score(category: str, row: Mapping[str, Any], profile: str) -> float:
    if category not in SUPPORTED_PROFILES:
        raise ValueError(f"Unsupported peripheral category: {category}")
    if profile not in SUPPORTED_PROFILES[category]:
        raise ValueError(f"Unsupported {category} profile: {profile}")
    return round(_clamp(RULES[category](row, profile)), 6)


def feature_frame(frame: pd.DataFrame, category: str) -> pd.DataFrame:
    if category not in FEATURES:
        raise ValueError(f"Unsupported peripheral category: {category}")
    prepared = frame.copy()
    configured = FEATURES[category]
    for column in (*configured["numeric"], *configured["categorical"], *configured["text"]):
        if column not in prepared:
            prepared[column] = np.nan
    for column in configured["numeric"]:
        prepared[column] = pd.to_numeric(prepared[column], errors="coerce")
    for column in configured["categorical"]:
        prepared[column] = prepared[column].fillna("unknown").astype(str)
    prepared["feature_text"] = prepared[list(configured["text"])].fillna("").astype(str).agg(" ".join, axis=1)
    return prepared


def build_preprocessor(category: str) -> ColumnTransformer:
    configured = FEATURES[category]
    numeric = Pipeline(
        [
            ("imputer", SimpleImputer(strategy="median")),
            ("scale", StandardScaler(with_mean=False)),
        ]
    )
    categorical = Pipeline(
        [
            ("imputer", SimpleImputer(strategy="most_frequent")),
            ("one_hot", OneHotEncoder(handle_unknown="ignore")),
        ]
    )
    return ColumnTransformer(
        [
            ("numeric", numeric, list(configured["numeric"])),
            ("categorical", categorical, list(configured["categorical"])),
            ("text", TfidfVectorizer(ngram_range=(1, 2), min_df=2, max_features=5000), "feature_text"),
        ]
    )


def train_bundle(frame: pd.DataFrame, category: str) -> dict[str, Any]:
    prepared = feature_frame(frame, category)
    labels = prepared["primary_use_profile"].astype(str)
    invalid = sorted(set(labels) - set(SUPPORTED_PROFILES[category]))
    if invalid:
        raise ValueError(f"Unsupported training labels for {category}: {invalid}")
    preprocessor = build_preprocessor(category)
    matrix = preprocessor.fit_transform(prepared)
    classifier = LogisticRegression(
        max_iter=1500,
        class_weight="balanced",
        random_state=42,
        solver="liblinear",
    )
    classifier.fit(matrix, labels)
    centroids = {
        label: np.asarray(matrix[labels.to_numpy() == label].mean(axis=0)).ravel()
        for label in classifier.classes_
    }
    return {
        "algorithm_version": ALGORITHM_VERSION,
        "category": category,
        "production_ready": False,
        "training_authority": "reference_only_weak_labels",
        "preprocessor": preprocessor,
        "classifier": classifier,
        "centroids": centroids,
        "feature_columns": FEATURES[category],
        "score_weights": SCORE_WEIGHTS,
    }


def hybrid_score_components(
    bundle: Mapping[str, Any], frame: pd.DataFrame, profile: str
) -> pd.DataFrame:
    category = str(bundle["category"])
    if profile not in SUPPORTED_PROFILES[category]:
        raise ValueError(f"Unsupported {category} profile: {profile}")
    prepared = feature_frame(frame, category)
    matrix = bundle["preprocessor"].transform(prepared)
    probabilities = bundle["classifier"].predict_proba(matrix)
    class_index = list(bundle["classifier"].classes_).index(profile)
    learned = probabilities[:, class_index]
    centroid = np.asarray(bundle["centroids"][profile]).reshape(1, -1)
    similarity = cosine_similarity(matrix, centroid).ravel()
    rules = np.array([
        rule_score(category, row, profile)
        for row in prepared.to_dict(orient="records")
    ])
    quality = pd.to_numeric(
        prepared.get("completeness_score", pd.Series(0, index=prepared.index)),
        errors="coerce",
    ).fillna(0).clip(0, 100).to_numpy() / 100
    total = (
        rules * SCORE_WEIGHTS["rule_fit"]
        + learned * SCORE_WEIGHTS["learned_purpose_probability"]
        + similarity * SCORE_WEIGHTS["profile_similarity"]
        + quality * SCORE_WEIGHTS["data_completeness"]
    )
    return pd.DataFrame(
        {
            "rule_fit": rules,
            "learned_purpose_probability": learned,
            "profile_similarity": similarity,
            "data_completeness": quality,
            "score": np.clip(total, 0, 1),
        },
        index=frame.index,
    )


def rank_peripherals(
    bundle: Mapping[str, Any],
    candidates: pd.DataFrame,
    profile: str,
    *,
    limit: int = 5,
    allow_unverified: bool = False,
) -> dict[str, Any]:
    if not 1 <= limit <= 20:
        raise ValueError("limit must be between 1 and 20")
    category = str(bundle["category"])
    frame = candidates.copy()
    if "accessory_type" in frame:
        frame = frame[frame["accessory_type"] == category]
    original_count = len(frame)
    if not allow_unverified:
        eligible = frame.get("recommendation_eligible", False)
        verified = frame.get("review_status", "")
        if isinstance(eligible, pd.Series):
            frame = frame[
                eligible.map(_truthy)
                & verified.astype(str).str.casefold().eq("verified")
            ]
        else:
            frame = frame.head(0)
    if frame.empty:
        return {
            "algorithm_version": ALGORITHM_VERSION,
            "category": category,
            "profile": profile,
            "mode": "experimental" if allow_unverified else "production",
            "candidate_count": original_count,
            "eligible_candidate_count": 0,
            "recommendations": [],
            "blocked_unverified_count": original_count if not allow_unverified else 0,
        }
    components = hybrid_score_components(bundle, frame, profile)
    ranked = frame.copy()
    for column in components:
        ranked[column] = components[column]
    ranked = ranked.sort_values(
        ["score", "identity_key"], ascending=[False, True]
    ).head(limit)
    recommendations: list[dict[str, Any]] = []
    for row in ranked.to_dict(orient="records"):
        recommendations.append(
            {
                "source_record_id": row.get("source_record_id"),
                "identity_key": row.get("identity_key"),
                "name": row.get("raw_name") or f"{row.get('brand', '')} {row.get('model', '')}".strip(),
                "brand": row.get("brand"),
                "model": row.get("model"),
                "score": round(float(row["score"]) * 100, 2),
                "score_breakdown": {
                    key: round(float(row[key]) * 100, 2) for key in SCORE_WEIGHTS
                },
                "reasons": [
                    f"Technical profile fit: {float(row['rule_fit']) * 100:.1f}/100.",
                    f"Learned purpose agreement: {float(row['learned_purpose_probability']) * 100:.1f}/100.",
                    f"Similarity to the {profile.replace('_', ' ')} profile: {float(row['profile_similarity']) * 100:.1f}/100.",
                    "Requires trusted specification and live-offer verification."
                    if allow_unverified else "Uses a verified canonical product candidate.",
                ],
            }
        )
    return {
        "algorithm_version": ALGORITHM_VERSION,
        "category": category,
        "profile": profile,
        "mode": "experimental" if allow_unverified else "production",
        "candidate_count": original_count,
        "eligible_candidate_count": len(frame),
        "recommendations": recommendations,
        "blocked_unverified_count": 0,
    }
