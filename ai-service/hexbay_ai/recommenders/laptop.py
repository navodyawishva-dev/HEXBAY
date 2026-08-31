"""Explainable content-based laptop ranking for HEXBAY.

The live candidate list must come from PHP/MySQL. This module never reads
marketplace prices or stock from Kaggle data and never mutates marketplace
state.
"""

from __future__ import annotations

import math
import re
from collections import Counter
from dataclasses import dataclass
from typing import Any, Mapping, Sequence

import numpy as np
from sklearn.metrics.pairwise import cosine_similarity


ALGORITHM_VERSION = "laptop-content-v1.1.0"
MAX_CANDIDATES = 500
MAX_RESULTS = 20

SUPPORTED_USES = {
    "any",
    "general",
    "office",
    "study",
    "programming",
    "gaming",
    "content_creation",
    "engineering",
}

SCORE_WEIGHTS = {
    "content_similarity": 0.28,
    "specification_headroom": 0.25,
    "price_fit": 0.20,
    "preference_fit": 0.10,
    "rating_confidence": 0.08,
    "vendor_reliability": 0.05,
    "availability": 0.04,
}

# [RAM, storage, CPU, GPU, screen scale, portability]
PURPOSE_PROFILES = {
    "any": np.array([0.50, 0.45, 0.50, 0.40, 0.55, 0.50]),
    "general": np.array([0.40, 0.35, 0.40, 0.20, 0.55, 0.65]),
    "office": np.array([0.35, 0.30, 0.40, 0.15, 0.55, 0.75]),
    "study": np.array([0.35, 0.35, 0.40, 0.15, 0.50, 0.80]),
    "programming": np.array([0.65, 0.55, 0.75, 0.25, 0.55, 0.50]),
    "gaming": np.array([0.70, 0.60, 0.85, 1.00, 0.65, 0.20]),
    "content_creation": np.array([0.80, 0.75, 0.90, 0.90, 0.70, 0.25]),
    "engineering": np.array([0.75, 0.65, 0.90, 0.75, 0.65, 0.30]),
}

PURPOSE_MINIMUMS = {
    "any": (8.0, 256.0, 0.30, 0.10),
    "general": (8.0, 256.0, 0.30, 0.10),
    "office": (8.0, 256.0, 0.30, 0.05),
    "study": (8.0, 256.0, 0.30, 0.05),
    "programming": (16.0, 512.0, 0.60, 0.10),
    "gaming": (16.0, 512.0, 0.70, 0.70),
    "content_creation": (16.0, 512.0, 0.70, 0.60),
    "engineering": (16.0, 512.0, 0.70, 0.50),
}


class RecommendationInputError(ValueError):
    """Raised when an internal recommendation request is invalid."""

    def __init__(self, message: str, errors: Mapping[str, Sequence[str]]) -> None:
        super().__init__(message)
        self.errors = {key: list(value) for key, value in errors.items()}


def _bounded_number(
    value: Any,
    *,
    field: str,
    minimum: float,
    maximum: float,
    required: bool = True,
) -> float | None:
    if value is None:
        if required:
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {field: ["This value is required."]},
            )
        return None
    if isinstance(value, bool):
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: ["Enter a valid number."]},
        )
    try:
        number = float(value)
    except (TypeError, ValueError) as error:
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: ["Enter a valid number."]},
        ) from error
    if not math.isfinite(number) or number < minimum or number > maximum:
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: [f"Enter a value between {minimum:g} and {maximum:g}."]},
        )
    return number


def _bounded_integer(
    value: Any,
    *,
    field: str,
    minimum: int,
    maximum: int,
    required: bool = True,
) -> int | None:
    number = _bounded_number(
        value,
        field=field,
        minimum=float(minimum),
        maximum=float(maximum),
        required=required,
    )
    if number is None:
        return None
    if not number.is_integer():
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: ["Enter a whole number."]},
        )
    return int(number)


def _bounded_string(
    value: Any,
    *,
    field: str,
    maximum: int = 190,
    required: bool = True,
) -> str | None:
    if value is None:
        if required:
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {field: ["This value is required."]},
            )
        return None
    if not isinstance(value, str):
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: ["Enter valid text."]},
        )
    cleaned = re.sub(r"\s+", " ", value).strip()
    if (required and not cleaned) or len(cleaned) > maximum:
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: [f"Enter between 1 and {maximum} characters."]},
        )
    return cleaned or None


def _normalised(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", " ", value.casefold()).strip()


def _string_list(value: Any, *, field: str, maximum_items: int = 20) -> tuple[str, ...]:
    if value is None:
        return ()
    if not isinstance(value, list) or len(value) > maximum_items:
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {field: [f"Provide a list containing at most {maximum_items} values."]},
        )
    cleaned: list[str] = []
    for index, item in enumerate(value):
        text = _bounded_string(
            item,
            field=f"{field}.{index}",
            maximum=80,
            required=True,
        )
        if text and text.casefold() not in {existing.casefold() for existing in cleaned}:
            cleaned.append(text)
    return tuple(cleaned)


@dataclass(frozen=True)
class LaptopRequirements:
    max_budget_lkr: float
    intended_use: str
    minimum_budget_lkr: float | None = None
    minimum_ram_gb: float | None = None
    maximum_ram_gb: float | None = None
    minimum_storage_gb: float | None = None
    required_gpu: str | None = None
    required_cpu: str | None = None
    require_dedicated_gpu: bool = False
    minimum_screen_size_inches: float | None = None
    maximum_screen_size_inches: float | None = None
    preferred_brands: tuple[str, ...] = ()
    preferred_screen_size_inches: float | None = None

    @classmethod
    def from_mapping(cls, raw: Mapping[str, Any]) -> "LaptopRequirements":
        max_budget_lkr = _bounded_number(
            raw.get("max_budget_lkr"),
            field="requirements.max_budget_lkr",
            minimum=1_000,
            maximum=100_000_000,
        )
        intended_use = _bounded_string(
            raw.get("intended_use"),
            field="requirements.intended_use",
            maximum=40,
        )
        assert max_budget_lkr is not None and intended_use is not None
        intended_use = intended_use.casefold().replace(" ", "_")
        if intended_use not in SUPPORTED_USES:
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {
                    "requirements.intended_use": [
                        "Choose one of: " + ", ".join(sorted(SUPPORTED_USES)) + "."
                    ]
                },
            )
        minimum_screen = _bounded_number(
            raw.get("minimum_screen_size_inches"),
            field="requirements.minimum_screen_size_inches",
            minimum=8,
            maximum=30,
            required=False,
        )
        maximum_screen = _bounded_number(
            raw.get("maximum_screen_size_inches"),
            field="requirements.maximum_screen_size_inches",
            minimum=8,
            maximum=30,
            required=False,
        )
        if (
            minimum_screen is not None
            and maximum_screen is not None
            and minimum_screen > maximum_screen
        ):
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {
                    "requirements.maximum_screen_size_inches": [
                        "Maximum screen size must not be smaller than the minimum."
                    ]
                },
            )
        minimum_budget = _bounded_number(
            raw.get("minimum_budget_lkr"),
            field="requirements.minimum_budget_lkr",
            minimum=1_000,
            maximum=100_000_000,
            required=False,
        )
        maximum_ram = _bounded_number(
            raw.get("maximum_ram_gb"),
            field="requirements.maximum_ram_gb",
            minimum=1,
            maximum=512,
            required=False,
        )
        minimum_ram = _bounded_number(
            raw.get("minimum_ram_gb"),
            field="requirements.minimum_ram_gb",
            minimum=1,
            maximum=512,
            required=False,
        )
        range_errors: dict[str, list[str]] = {}
        if minimum_budget is not None and minimum_budget > max_budget_lkr:
            range_errors["requirements.minimum_budget_lkr"] = [
                "Minimum budget must not be greater than the maximum."
            ]
        if maximum_ram is not None and minimum_ram is not None and minimum_ram > maximum_ram:
            range_errors["requirements.maximum_ram_gb"] = [
                "Maximum RAM must not be smaller than the minimum."
            ]
        if range_errors:
            raise RecommendationInputError(
                "Recommendation request validation failed.", range_errors
            )
        require_dedicated = raw.get("require_dedicated_gpu", False)
        if not isinstance(require_dedicated, bool):
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {
                    "requirements.require_dedicated_gpu": [
                        "Use true or false for this value."
                    ]
                },
            )
        return cls(
            max_budget_lkr=max_budget_lkr,
            intended_use=intended_use,
            minimum_budget_lkr=minimum_budget,
            minimum_ram_gb=minimum_ram,
            maximum_ram_gb=maximum_ram,
            minimum_storage_gb=_bounded_number(
                raw.get("minimum_storage_gb"),
                field="requirements.minimum_storage_gb",
                minimum=16,
                maximum=100_000,
                required=False,
            ),
            required_gpu=_bounded_string(
                raw.get("required_gpu"),
                field="requirements.required_gpu",
                maximum=100,
                required=False,
            ),
            required_cpu=_bounded_string(
                raw.get("required_cpu"),
                field="requirements.required_cpu",
                maximum=100,
                required=False,
            ),
            require_dedicated_gpu=require_dedicated,
            minimum_screen_size_inches=minimum_screen,
            maximum_screen_size_inches=maximum_screen,
            preferred_brands=_string_list(
                raw.get("preferred_brands"),
                field="requirements.preferred_brands",
                maximum_items=10,
            ),
            preferred_screen_size_inches=_bounded_number(
                raw.get("preferred_screen_size_inches"),
                field="requirements.preferred_screen_size_inches",
                minimum=8,
                maximum=30,
                required=False,
            ),
        )


@dataclass(frozen=True)
class LaptopCandidate:
    product_id: int
    listing_id: int | None
    name: str
    brand: str
    price_lkr: float
    ram_gb: float
    storage_gb: float
    cpu: str
    gpu: str
    screen_size_inches: float | None
    weight_kg: float | None
    tags: tuple[str, ...]
    rating_average: float | None
    rating_count: int
    vendor_rating: float | None
    stock_quantity: int
    eligible: bool

    @classmethod
    def from_mapping(
        cls, raw: Mapping[str, Any], *, field_prefix: str
    ) -> "LaptopCandidate":
        product_id = _bounded_integer(
            raw.get("product_id"),
            field=f"{field_prefix}.product_id",
            minimum=1,
            maximum=9_223_372_036_854_775_807,
        )
        listing_id = _bounded_integer(
            raw.get("listing_id"),
            field=f"{field_prefix}.listing_id",
            minimum=1,
            maximum=9_223_372_036_854_775_807,
            required=False,
        )
        name = _bounded_string(raw.get("name"), field=f"{field_prefix}.name")
        brand = _bounded_string(raw.get("brand"), field=f"{field_prefix}.brand")
        cpu = _bounded_string(raw.get("cpu"), field=f"{field_prefix}.cpu")
        gpu = _bounded_string(raw.get("gpu"), field=f"{field_prefix}.gpu")
        price_lkr = _bounded_number(
            raw.get("price_lkr"),
            field=f"{field_prefix}.price_lkr",
            minimum=0.01,
            maximum=100_000_000,
        )
        ram_gb = _bounded_number(
            raw.get("ram_gb"),
            field=f"{field_prefix}.ram_gb",
            minimum=1,
            maximum=512,
        )
        storage_gb = _bounded_number(
            raw.get("storage_gb"),
            field=f"{field_prefix}.storage_gb",
            minimum=1,
            maximum=100_000,
        )
        stock = _bounded_integer(
            raw.get("stock_quantity"),
            field=f"{field_prefix}.stock_quantity",
            minimum=0,
            maximum=10_000_000,
        )
        rating_count = _bounded_integer(
            raw.get("rating_count", 0),
            field=f"{field_prefix}.rating_count",
            minimum=0,
            maximum=10_000_000,
        )
        eligible = raw.get("eligible", True)
        if not isinstance(eligible, bool):
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {f"{field_prefix}.eligible": ["Use true or false for this value."]},
            )
        assert None not in (
            product_id,
            name,
            brand,
            cpu,
            gpu,
            price_lkr,
            ram_gb,
            storage_gb,
            stock,
            rating_count,
        )
        return cls(
            product_id=product_id,
            listing_id=listing_id,
            name=name,
            brand=brand,
            price_lkr=price_lkr,
            ram_gb=ram_gb,
            storage_gb=storage_gb,
            cpu=cpu,
            gpu=gpu,
            screen_size_inches=_bounded_number(
                raw.get("screen_size_inches"),
                field=f"{field_prefix}.screen_size_inches",
                minimum=8,
                maximum=30,
                required=False,
            ),
            weight_kg=_bounded_number(
                raw.get("weight_kg"),
                field=f"{field_prefix}.weight_kg",
                minimum=0.2,
                maximum=15,
                required=False,
            ),
            tags=_string_list(
                raw.get("tags"),
                field=f"{field_prefix}.tags",
                maximum_items=20,
            ),
            rating_average=_bounded_number(
                raw.get("rating_average"),
                field=f"{field_prefix}.rating_average",
                minimum=0,
                maximum=5,
                required=False,
            ),
            rating_count=rating_count,
            vendor_rating=_bounded_number(
                raw.get("vendor_rating"),
                field=f"{field_prefix}.vendor_rating",
                minimum=0,
                maximum=5,
                required=False,
            ),
            stock_quantity=stock,
            eligible=eligible,
        )


def _cpu_tier(value: str) -> float:
    text = _normalised(value)
    patterns = (
        (r"\b(core ultra 9|core i9|ryzen 9|apple m[3-9] max)\b", 1.00),
        (r"\b(core ultra 7|core i7|ryzen 7|apple m[2-9] pro)\b", 0.85),
        (r"\b(core ultra 5|core i5|ryzen 5|apple m[1-9])\b", 0.68),
        (r"\b(core i3|ryzen 3)\b", 0.45),
        (r"\b(xeon|threadripper)\b", 0.90),
        (r"\b(celeron|pentium|athlon|atom)\b", 0.22),
    )
    for pattern, score in patterns:
        if re.search(pattern, text):
            return score
    return 0.40


def _gpu_tier(value: str) -> float:
    text = _normalised(value)
    if re.search(r"\b(rtx 50\d\d|rtx 4090|rx 7900)\b", text):
        return 1.00
    if re.search(r"\b(rtx 40\d\d|rtx 30[789]0|rx 7[789]\d\d)\b", text):
        return 0.90
    if re.search(r"\b(rtx 30\d\d|rtx 20\d\d|rx 6[789]\d\d)\b", text):
        return 0.78
    if re.search(r"\b(rtx|gtx|radeon pro|quadro|arc [a-z]\d+)\b", text):
        return 0.66
    if re.search(r"\b(mx\d+|radeon \d+|dedicated)\b", text):
        return 0.45
    if re.search(r"\b(iris|uhd|integrated|vega|hd graphics)\b", text):
        return 0.20
    return 0.30


def _has_dedicated_gpu(value: str) -> bool:
    text = _normalised(value)
    integrated = r"\b(iris|uhd|integrated|vega \d*|hd graphics)\b"
    dedicated = r"\b(rtx|gtx|geforce|radeon pro|rx \d+|quadro|arc [a-z]\d+|mx\d+)\b"
    return bool(re.search(dedicated, text)) and not bool(re.search(integrated, text))


def _has_catalogue_tag(candidate: LaptopCandidate, tag: str) -> bool:
    expected = _normalised(tag)
    return any(_normalised(value) == expected for value in candidate.tags)


def _is_gaming_capable(candidate: LaptopCandidate) -> bool:
    return (
        _has_dedicated_gpu(candidate.gpu)
        and _gpu_tier(candidate.gpu) >= 0.66
        and _cpu_tier(candidate.cpu) >= 0.68
        and candidate.ram_gb >= 8
    )


def _candidate_vector(candidate: LaptopCandidate) -> np.ndarray:
    screen_scale = (
        min(candidate.screen_size_inches / 17.3, 1.0)
        if candidate.screen_size_inches is not None
        else 0.55
    )
    portability = (
        max(0.0, min(1.0, 1.0 - ((candidate.weight_kg - 1.0) / 2.5)))
        if candidate.weight_kg is not None
        else 0.50
    )
    return np.array(
        [
            min(candidate.ram_gb / 64.0, 1.0),
            min(candidate.storage_gb / 2048.0, 1.0),
            _cpu_tier(candidate.cpu),
            _gpu_tier(candidate.gpu),
            screen_scale,
            portability,
        ]
    )


def _hard_filter_reasons(
    candidate: LaptopCandidate, requirements: LaptopRequirements
) -> list[str]:
    reasons: list[str] = []
    if not candidate.eligible:
        reasons.append("candidate_not_eligible")
    if candidate.stock_quantity < 1:
        reasons.append("out_of_stock")
    if candidate.price_lkr > requirements.max_budget_lkr:
        reasons.append("over_budget")
    if (
        requirements.minimum_budget_lkr is not None
        and candidate.price_lkr < requirements.minimum_budget_lkr
    ):
        reasons.append("below_budget_range")
    if (
        requirements.minimum_ram_gb is not None
        and candidate.ram_gb < requirements.minimum_ram_gb
    ):
        reasons.append("insufficient_ram")
    if (
        requirements.maximum_ram_gb is not None
        and candidate.ram_gb > requirements.maximum_ram_gb
    ):
        reasons.append("ram_above_maximum")
    if (
        requirements.minimum_storage_gb is not None
        and candidate.storage_gb < requirements.minimum_storage_gb
    ):
        reasons.append("insufficient_storage")
    if requirements.required_gpu and _normalised(requirements.required_gpu) not in _normalised(
        candidate.gpu
    ):
        reasons.append("required_gpu_not_matched")
    if requirements.required_cpu and _normalised(requirements.required_cpu) not in _normalised(
        candidate.cpu
    ):
        reasons.append("required_cpu_not_matched")
    if requirements.require_dedicated_gpu and not _has_dedicated_gpu(candidate.gpu):
        reasons.append("dedicated_gpu_required")
    if requirements.intended_use == "gaming":
        if not _has_catalogue_tag(candidate, "gaming"):
            reasons.append("gaming_classification_required")
        if not _has_dedicated_gpu(candidate.gpu) or _gpu_tier(candidate.gpu) < 0.66:
            reasons.append("gaming_hardware_required")
    if (
        requirements.minimum_screen_size_inches is not None
        and (
            candidate.screen_size_inches is None
            or candidate.screen_size_inches < requirements.minimum_screen_size_inches
        )
    ):
        reasons.append("screen_below_minimum")
    if (
        requirements.maximum_screen_size_inches is not None
        and (
            candidate.screen_size_inches is None
            or candidate.screen_size_inches > requirements.maximum_screen_size_inches
        )
    ):
        reasons.append("screen_above_maximum")
    return reasons


def _specification_headroom(
    candidate: LaptopCandidate, requirements: LaptopRequirements
) -> float:
    purpose_ram, purpose_storage, purpose_cpu, purpose_gpu = PURPOSE_MINIMUMS[
        requirements.intended_use
    ]
    target_ram = max(requirements.minimum_ram_gb or 0, purpose_ram)
    target_storage = max(requirements.minimum_storage_gb or 0, purpose_storage)
    parts = [
        min(candidate.ram_gb / target_ram, 1.0),
        min(candidate.storage_gb / target_storage, 1.0),
        min(_cpu_tier(candidate.cpu) / purpose_cpu, 1.0),
        min(_gpu_tier(candidate.gpu) / purpose_gpu, 1.0),
    ]
    return float(sum(parts) / len(parts))


def _price_fit(candidate: LaptopCandidate, requirements: LaptopRequirements) -> float:
    minimum = requirements.minimum_budget_lkr or 0.0
    span = max(requirements.max_budget_lkr - minimum, 1.0)
    ratio = min(max((candidate.price_lkr - minimum) / span, 0.0), 1.0)
    return 1.0 - (0.5 * ratio)


def _preference_fit(
    candidate: LaptopCandidate, requirements: LaptopRequirements
) -> float:
    parts: list[float] = []
    if requirements.preferred_brands:
        preferred = {_normalised(brand) for brand in requirements.preferred_brands}
        parts.append(1.0 if _normalised(candidate.brand) in preferred else 0.35)
    if requirements.preferred_screen_size_inches is not None:
        if candidate.screen_size_inches is None:
            parts.append(0.30)
        else:
            difference = abs(
                candidate.screen_size_inches - requirements.preferred_screen_size_inches
            )
            parts.append(max(0.0, 1.0 - (difference / 5.0)))
    if not parts:
        return 0.60
    return sum(parts) / len(parts)


def _rating_confidence(candidate: LaptopCandidate) -> float:
    if candidate.rating_average is None or candidate.rating_count == 0:
        return 0.60
    prior_average = 4.0
    prior_count = 10
    smoothed = (
        (candidate.rating_average * candidate.rating_count)
        + (prior_average * prior_count)
    ) / (candidate.rating_count + prior_count)
    return max(0.0, min(1.0, smoothed / 5.0))


def _availability(candidate: LaptopCandidate) -> float:
    return min(1.0, 0.40 + (math.log1p(candidate.stock_quantity) / math.log1p(10)) * 0.60)


def _score_candidate(
    candidate: LaptopCandidate, requirements: LaptopRequirements
) -> tuple[float, dict[str, float], list[str]]:
    content_similarity = (
        0.75
        if requirements.intended_use == "any"
        else float(
            cosine_similarity(
                [_candidate_vector(candidate)],
                [PURPOSE_PROFILES[requirements.intended_use]],
            )[0][0]
        )
    )
    breakdown = {
        "content_similarity": content_similarity,
        "specification_headroom": _specification_headroom(candidate, requirements),
        "price_fit": _price_fit(candidate, requirements),
        "preference_fit": _preference_fit(candidate, requirements),
        "rating_confidence": _rating_confidence(candidate),
        "vendor_reliability": (
            candidate.vendor_rating / 5.0
            if candidate.vendor_rating is not None
            else 0.60
        ),
        "availability": _availability(candidate),
    }
    weighted_score = sum(
        breakdown[name] * weight for name, weight in SCORE_WEIGHTS.items()
    )
    budget_reason = (
        f"Within your Rs. {requirements.minimum_budget_lkr:,.0f}–"
        f"{requirements.max_budget_lkr:,.0f} range at Rs. {candidate.price_lkr:,.0f}."
        if requirements.minimum_budget_lkr is not None
        else f"Within your Rs. {requirements.max_budget_lkr:,.0f} budget "
        f"at Rs. {candidate.price_lkr:,.0f}."
    )
    use_reason = (
        "Included because you selected any use; no use-case category was excluded."
        if requirements.intended_use == "any"
        else f"{candidate.cpu} and {candidate.gpu} align with "
        f"{requirements.intended_use.replace('_', ' ')} use."
    )
    reasons = [
        budget_reason,
        f"{candidate.ram_gb:g} GB RAM and {candidate.storage_gb:g} GB storage.",
        use_reason,
    ]
    if requirements.preferred_brands and _normalised(candidate.brand) in {
        _normalised(brand) for brand in requirements.preferred_brands
    }:
        reasons.append(f"Matches your preferred {candidate.brand} brand.")
    if candidate.rating_average is not None and candidate.rating_count > 0:
        reasons.append(
            f"Review confidence uses {candidate.rating_average:.1f}/5 "
            f"from {candidate.rating_count} review(s), adjusted for sample size."
        )
    reasons.append(f"{candidate.stock_quantity} currently available.")
    return (
        round(weighted_score * 100, 2),
        {name: round(value, 4) for name, value in breakdown.items()},
        reasons,
    )


def _relaxation_suggestions(reason_counts: Counter[str]) -> list[str]:
    suggestions: list[str] = []
    mapping = (
        ("over_budget", "Increase the maximum budget."),
        ("below_budget_range", "Reduce or remove the minimum budget."),
        ("insufficient_ram", "Reduce the minimum RAM requirement."),
        ("ram_above_maximum", "Increase or remove the maximum RAM requirement."),
        ("insufficient_storage", "Reduce the minimum storage requirement."),
        ("required_gpu_not_matched", "Make the exact GPU a preference instead of a requirement."),
        ("required_cpu_not_matched", "Make the exact CPU a preference instead of a requirement."),
        ("dedicated_gpu_required", "Allow integrated graphics."),
        (
            "gaming_classification_required",
            "Ask to see separately labelled gaming-capable alternatives.",
        ),
        (
            "gaming_hardware_required",
            "Increase the budget for a laptop with suitable dedicated graphics.",
        ),
        ("screen_below_minimum", "Reduce the minimum screen size."),
        ("screen_above_maximum", "Increase the maximum screen size."),
    )
    for reason, message in mapping:
        if reason_counts[reason]:
            suggestions.append(message)
        if len(suggestions) == 3:
            break
    return suggestions


def recommend_laptops(payload: Mapping[str, Any]) -> dict[str, Any]:
    if not isinstance(payload, Mapping):
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {"request": ["Send a JSON object."]},
        )
    raw_requirements = payload.get("requirements")
    raw_candidates = payload.get("candidates")
    if not isinstance(raw_requirements, Mapping):
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {"requirements": ["Send a requirements object."]},
        )
    if not isinstance(raw_candidates, list) or not raw_candidates:
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {"candidates": ["Provide at least one laptop candidate."]},
        )
    if len(raw_candidates) > MAX_CANDIDATES:
        raise RecommendationInputError(
            "Recommendation request validation failed.",
            {"candidates": [f"Provide no more than {MAX_CANDIDATES} candidates."]},
        )
    limit = _bounded_integer(
        payload.get("limit", 5),
        field="limit",
        minimum=1,
        maximum=MAX_RESULTS,
    )
    assert limit is not None
    requirements = LaptopRequirements.from_mapping(raw_requirements)

    candidates: list[LaptopCandidate] = []
    seen_product_ids: set[int] = set()
    for index, raw_candidate in enumerate(raw_candidates):
        if not isinstance(raw_candidate, Mapping):
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {f"candidates.{index}": ["Send a candidate object."]},
            )
        candidate = LaptopCandidate.from_mapping(
            raw_candidate, field_prefix=f"candidates.{index}"
        )
        if candidate.product_id in seen_product_ids:
            raise RecommendationInputError(
                "Recommendation request validation failed.",
                {
                    f"candidates.{index}.product_id": [
                        "Each canonical product may appear only once."
                    ]
                },
            )
        seen_product_ids.add(candidate.product_id)
        candidates.append(candidate)

    filtered: list[dict[str, Any]] = []
    eligible: list[LaptopCandidate] = []
    gaming_capable: list[LaptopCandidate] = []
    reason_counts: Counter[str] = Counter()
    for candidate in candidates:
        reasons = _hard_filter_reasons(candidate, requirements)
        if reasons:
            reason_counts.update(reasons)
            filtered.append(
                {"product_id": candidate.product_id, "reason_codes": reasons}
            )
            if (
                requirements.intended_use == "gaming"
                and set(reasons) == {"gaming_classification_required"}
                and _is_gaming_capable(candidate)
            ):
                gaming_capable.append(candidate)
        else:
            eligible.append(candidate)

    recommendations: list[dict[str, Any]] = []
    for candidate in eligible:
        score, breakdown, reasons = _score_candidate(candidate, requirements)
        recommendations.append(
            {
                "product_id": candidate.product_id,
                "listing_id": candidate.listing_id,
                "name": candidate.name,
                "brand": candidate.brand,
                "price_lkr": round(candidate.price_lkr, 2),
                "stock_quantity": candidate.stock_quantity,
                "score": score,
                "score_breakdown": breakdown,
                "reasons": reasons,
            }
        )
    recommendations.sort(
        key=lambda item: (-item["score"], item["price_lkr"], item["product_id"])
    )

    gaming_capable_alternatives: list[dict[str, Any]] = []
    for candidate in gaming_capable:
        score, breakdown, reasons = _score_candidate(candidate, requirements)
        gaming_capable_alternatives.append(
            {
                "product_id": candidate.product_id,
                "listing_id": candidate.listing_id,
                "name": candidate.name,
                "brand": candidate.brand,
                "price_lkr": round(candidate.price_lkr, 2),
                "stock_quantity": candidate.stock_quantity,
                "score": score,
                "score_breakdown": breakdown,
                "reasons": [
                    "Gaming-capable hardware, but HEXBAY does not classify this "
                    "as a dedicated gaming laptop.",
                    *reasons,
                ],
            }
        )
    gaming_capable_alternatives.sort(
        key=lambda item: (-item["score"], item["price_lkr"], item["product_id"])
    )

    return {
        "algorithm_version": ALGORITHM_VERSION,
        "candidate_count": len(candidates),
        "eligible_candidate_count": len(eligible),
        "recommendations": recommendations[:limit],
        "gaming_capable_alternative_count": len(gaming_capable),
        "gaming_capable_alternatives": gaming_capable_alternatives[:3],
        "filtered_out": filtered,
        "filter_summary": dict(sorted(reason_counts.items())),
        "relaxation_suggestions": (
            _relaxation_suggestions(reason_counts) if not recommendations else []
        ),
        "authority": {
            "price_and_stock": "PHP/MySQL candidate snapshot; revalidate before display or cart",
            "ranking": "Flask explainable content-based score",
        },
    }
