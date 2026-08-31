from pathlib import Path

import pandas as pd

from peripheral_dataset_pipeline import (
    headset_row,
    normalize_category,
    parse_brand_model,
    select_shortlist,
)


def test_brand_alias_and_repeated_brand_are_normalized() -> None:
    assert parse_brand_model("HP HyperX Alloy Core") == (
        "HyperX", "Alloy Core", "curated_alias"
    )
    assert parse_brand_model("Acer Acer Nitro XV272U") == (
        "Acer", "Nitro XV272U", "curated_alias"
    )


def test_monitor_identity_duplicates_collapse_without_using_price() -> None:
    source = pd.DataFrame(
        [
            {"name": "LG 27GP850", "price": 300, "screen_size": 27, "resolution": "2560,1440", "refresh_rate": 165, "response_time": 1, "panel_type": "IPS", "aspect_ratio": "16:9"},
            {"name": "LG 27GP850", "price": 350, "screen_size": 27, "resolution": "2560,1440", "refresh_rate": 165, "response_time": 1, "panel_type": "IPS", "aspect_ratio": "16:9"},
        ]
    )

    normalized, rejected, audit = normalize_category("monitor", source)

    assert rejected.empty
    assert len(normalized) == 1
    assert normalized.iloc[0]["source_variant_count"] == 2
    assert "price" not in normalized.columns
    assert not bool(normalized.iloc[0]["recommendation_eligible"])
    assert audit["identity_duplicates_collapsed"] == 1


def test_headset_frequency_is_retained_as_ambiguous_raw_evidence() -> None:
    details = headset_row(
        pd.Series({"type": "Circumaural", "frequency_response": "15,25", "microphone": "true", "wireless": "false", "enclosure_type": "Closed"})
    )
    assert details["frequency_response_raw"] == "15,25"
    assert "frequency_units_ambiguous" in details["_warnings"]


def test_shortlist_enforces_brand_diversity() -> None:
    frame = pd.DataFrame(
        [
            {"brand": "A", "model": str(index), "brand_confidence": "curated_alias", "completeness_score": 100, "curation_priority": 105 - index, "warning_flags": ""}
            for index in range(5)
        ]
        + [
            {"brand": "B", "model": str(index), "brand_confidence": "curated_alias", "completeness_score": 100, "curation_priority": 90 - index, "warning_flags": ""}
            for index in range(3)
        ]
    )
    shortlist = select_shortlist(frame, target=6, max_per_brand=3)
    assert len(shortlist) == 6
    assert shortlist.groupby("brand").size().max() == 3
