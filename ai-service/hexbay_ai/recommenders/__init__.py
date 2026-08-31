"""Explainable recommendation modules."""

from .laptop import (
    ALGORITHM_VERSION,
    RecommendationInputError,
    recommend_laptops,
)
from .peripheral import (
    ALGORITHM_VERSION as PERIPHERAL_ALGORITHM_VERSION,
    rank_peripherals,
    rule_score as peripheral_rule_score,
    train_bundle as train_peripheral_bundle,
)

__all__ = [
    "ALGORITHM_VERSION",
    "RecommendationInputError",
    "recommend_laptops",
    "PERIPHERAL_ALGORITHM_VERSION",
    "rank_peripherals",
    "peripheral_rule_score",
    "train_peripheral_bundle",
]
