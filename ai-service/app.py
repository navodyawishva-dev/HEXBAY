from __future__ import annotations

import hmac
import os
from functools import lru_cache
from pathlib import Path
from typing import Any

import joblib
import pandas as pd
from flask import Flask, jsonify, request

from hexbay_ai.recommenders import (
    ALGORITHM_VERSION,
    PERIPHERAL_ALGORITHM_VERSION,
    RecommendationInputError,
    rank_peripherals,
    recommend_laptops,
)
from hexbay_ai.recommenders.peripheral import SUPPORTED_PROFILES


SERVICE_ROOT = Path(__file__).resolve().parent
PERIPHERAL_MODEL_DIR = SERVICE_ROOT / "models" / "peripherals"


def _environment_flag(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.strip().casefold() in {"1", "true", "yes", "on"}


@lru_cache(maxsize=4)
def _peripheral_bundle(category: str):
    path = PERIPHERAL_MODEL_DIR / f"{category}_{PERIPHERAL_ALGORITHM_VERSION}.joblib"
    if not path.is_file():
        raise FileNotFoundError(f"Peripheral model artifact is missing: {path.name}")
    bundle = joblib.load(path)
    if (
        bundle.get("category") != category
        or bundle.get("algorithm_version") != PERIPHERAL_ALGORITHM_VERSION
    ):
        raise ValueError("The peripheral model artifact metadata is invalid.")
    return bundle


def _load_local_environment() -> None:
    env_path = Path(__file__).resolve().parent / ".env"
    if not env_path.exists():
        return
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip())


def _envelope(
    success: bool,
    message: str,
    *,
    data: Any = None,
    errors: Any = None,
):
    return {
        "success": success,
        "message": message,
        "data": data,
        "errors": errors,
    }


def create_app(test_config: dict[str, Any] | None = None) -> Flask:
    _load_local_environment()
    app = Flask(__name__)
    app.config.from_mapping(
        MAX_CONTENT_LENGTH=1024 * 1024,
        INTERNAL_SERVICE_SECRET=os.getenv("INTERNAL_SERVICE_SECRET", ""),
        ALLOW_EXPERIMENTAL_PERIPHERALS=_environment_flag(
            "ALLOW_EXPERIMENTAL_PERIPHERALS", False
        ),
        PERIPHERAL_RANKER=None,
    )
    if test_config:
        app.config.update(test_config)

    @app.get("/internal/health")
    def health():
        return jsonify(
            _envelope(
                True,
                "Hexbay intelligence service is healthy.",
                data={
                    "service": "flask-intelligence",
                    "sprint": 5,
                    "features_enabled": [
                        "health_check",
                        "dataset_preparation",
                        "laptop_recommendation",
                        "peripheral_hybrid_ranking",
                    ],
                    "laptop_algorithm_version": ALGORITHM_VERSION,
                    "peripheral_algorithm_version": PERIPHERAL_ALGORITHM_VERSION,
                    "experimental_peripheral_mode": bool(
                        app.config["ALLOW_EXPERIMENTAL_PERIPHERALS"]
                    ),
                },
            )
        )

    @app.post("/internal/recommend/laptops")
    def laptop_recommendations():
        expected_secret = str(app.config.get("INTERNAL_SERVICE_SECRET", ""))
        supplied_secret = request.headers.get("X-Hexbay-Internal-Secret", "")
        if not expected_secret:
            return (
                jsonify(
                    _envelope(
                        False,
                        "The intelligence service secret is not configured.",
                        errors={"service": ["Set INTERNAL_SERVICE_SECRET locally."]},
                    )
                ),
                503,
            )
        if not hmac.compare_digest(supplied_secret, expected_secret):
            return (
                jsonify(
                    _envelope(
                        False,
                        "Internal service authentication failed.",
                        errors={"authentication": ["The internal secret is invalid."]},
                    )
                ),
                401,
            )
        if not request.is_json:
            return (
                jsonify(
                    _envelope(
                        False,
                        "A JSON request body is required.",
                        errors={"content_type": ["Use application/json."]},
                    )
                ),
                415,
            )
        try:
            result = recommend_laptops(request.get_json(silent=False))
        except RecommendationInputError as error:
            return (
                jsonify(
                    _envelope(
                        False,
                        str(error),
                        errors=error.errors,
                    )
                ),
                422,
            )
        return jsonify(
            _envelope(
                True,
                "Laptop recommendations generated.",
                data=result,
            )
        )

    @app.post("/internal/recommend/peripherals")
    def peripheral_recommendations():
        expected_secret = str(app.config.get("INTERNAL_SERVICE_SECRET", ""))
        supplied_secret = request.headers.get("X-Hexbay-Internal-Secret", "")
        if not expected_secret:
            return jsonify(_envelope(
                False,
                "The intelligence service secret is not configured.",
                errors={"service": ["Set INTERNAL_SERVICE_SECRET locally."]},
            )), 503
        if not hmac.compare_digest(supplied_secret, expected_secret):
            return jsonify(_envelope(
                False,
                "Internal service authentication failed.",
                errors={"authentication": ["The internal secret is invalid."]},
            )), 401
        if not request.is_json:
            return jsonify(_envelope(
                False,
                "A JSON request body is required.",
                errors={"content_type": ["Use application/json."]},
            )), 415

        payload = request.get_json(silent=False)
        if not isinstance(payload, dict):
            return jsonify(_envelope(
                False,
                "Peripheral recommendation input is invalid.",
                errors={"request": ["Use a JSON object."]},
            )), 422
        category = str(payload.get("category", "")).strip().casefold()
        profile = str(payload.get("profile", "")).strip().casefold()
        candidates = payload.get("candidates")
        try:
            limit = int(payload.get("limit", 10))
        except (TypeError, ValueError):
            limit = 0
        errors: dict[str, list[str]] = {}
        if category not in SUPPORTED_PROFILES:
            errors["category"] = ["Choose monitor, keyboard, mouse or headset."]
        elif profile not in SUPPORTED_PROFILES[category]:
            errors["profile"] = [f"The {profile or 'empty'} profile is not supported for {category}."]
        if not isinstance(candidates, list) or not candidates or len(candidates) > 500:
            errors["candidates"] = ["Provide between 1 and 500 canonical candidates."]
        elif any(not isinstance(item, dict) for item in candidates):
            errors["candidates"] = ["Every candidate must be a JSON object."]
        if not 1 <= limit <= 20:
            errors["limit"] = ["Choose a limit from 1 to 20."]
        requested_experimental = bool(payload.get("allow_unverified", False))
        if requested_experimental and not app.config["ALLOW_EXPERIMENTAL_PERIPHERALS"]:
            errors["allow_unverified"] = [
                "Experimental peripheral ranking is disabled on this service."
            ]
        if errors:
            return jsonify(_envelope(
                False,
                "Peripheral recommendation input is invalid.",
                errors=errors,
            )), 422

        try:
            runner = app.config.get("PERIPHERAL_RANKER")
            if callable(runner):
                result = runner(payload)
            else:
                result = rank_peripherals(
                    _peripheral_bundle(category),
                    pd.DataFrame(candidates),
                    profile,
                    limit=limit,
                    allow_unverified=requested_experimental,
                )
        except (FileNotFoundError, ValueError) as error:
            return jsonify(_envelope(
                False,
                "The peripheral ranking service is not ready.",
                errors={"service": [str(error)]},
            )), 503
        return jsonify(_envelope(
            True,
            "Peripheral recommendations generated.",
            data=result,
        ))

    return app


if __name__ == "__main__":
    create_app().run(
        host=os.getenv("FLASK_HOST", "127.0.0.1"),
        port=int(os.getenv("FLASK_PORT", "5000")),
        debug=False,
    )
