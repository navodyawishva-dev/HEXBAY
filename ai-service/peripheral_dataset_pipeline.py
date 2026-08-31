"""Normalize quarantined peripheral candidates for HEXBAY Step 2.

The output is a review queue, not a production catalogue. No row from the
reference-only source becomes recommendation-eligible in this pipeline.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Any, Callable

import pandas as pd


PIPELINE_VERSION = "1.0.0"
SERVICE_ROOT = Path(__file__).resolve().parent
DEFAULT_RAW_DIR = SERVICE_ROOT / "data" / "raw" / "peripheral-step1" / "reference-only" / "docyx"
DEFAULT_OUTPUT_DIR = SERVICE_ROOT / "data" / "processed" / "peripherals"
DEFAULT_SCHEMA = SERVICE_ROOT / "data" / "peripheral_catalogue_schema.json"

SOURCE_CODE = "docyx_pc_part_dataset_2025_07_23"
SOURCE_TRUST_STATUS = "reference_only_needs_verification"
CATEGORY_FILES = {
    "monitor": "monitor.csv",
    "keyboard": "keyboard.csv",
    "mouse": "mouse.csv",
    "headset": "headphones.csv",
}
SHORTLIST_TARGETS = {"monitor": 25, "keyboard": 20, "mouse": 20, "headset": 15}
CATEGORY_PREFERRED_BRANDS = {
    "monitor": ["ASUS", "Acer", "LG", "Samsung", "MSI", "Dell", "AOC", "BenQ", "ViewSonic", "Gigabyte"],
    "keyboard": ["Logitech", "Corsair", "Razer", "HyperX", "Keychron", "SteelSeries", "ASUS", "Cooler Master", "Redragon", "Royal Kludge", "Ducky"],
    "mouse": ["Logitech", "Razer", "Corsair", "SteelSeries", "ASUS", "Cooler Master", "HyperX", "Pulsar", "BenQ", "ROCCAT"],
    "headset": ["HyperX", "Logitech", "Razer", "SteelSeries", "Corsair", "Sennheiser", "ASUS", "Sony", "Audio-Technica", "Cooler Master"],
}
PROFILE_ORDER = {
    "monitor": ["general", "productivity", "visual_creative", "gaming", "competitive_gaming"],
    "keyboard": ["productivity", "portable", "ergonomic", "gaming"],
    "mouse": ["productivity", "portable", "accessibility", "gaming"],
    "headset": ["communication", "music_creation", "portable", "gaming"],
}

BRAND_PREFIXES = {
    "hp hyperx": ("HyperX", "curated_alias"),
    "rk royal kludge": ("Royal Kludge", "curated_alias"),
    "cooler master": ("Cooler Master", "curated_alias"),
    "das keyboard": ("Das Keyboard", "curated_alias"),
    "mad catz": ("Mad Catz", "curated_alias"),
    "endgame gear": ("Endgame Gear", "curated_alias"),
    "turtle beach": ("Turtle Beach", "curated_alias"),
    "cyber acoustics": ("Cyber Acoustics", "curated_alias"),
    "audio-technica": ("Audio-Technica", "curated_alias"),
    "royal kludge": ("Royal Kludge", "curated_alias"),
    "steelseries": ("SteelSeries", "curated_alias"),
    "viewsonic": ("ViewSonic", "curated_alias"),
    "microsoft": ("Microsoft", "curated_alias"),
    "thermaltake": ("Thermaltake", "curated_alias"),
    "sennheiser": ("Sennheiser", "curated_alias"),
    "beyerdynamic": ("Beyerdynamic", "curated_alias"),
    "skullcandy": ("Skullcandy", "curated_alias"),
    "plantronics": ("Plantronics", "curated_alias"),
    "redragon": ("Redragon", "curated_alias"),
    "keychron": ("Keychron", "curated_alias"),
    "logitech": ("Logitech", "curated_alias"),
    "corsair": ("Corsair", "curated_alias"),
    "gigabyte": ("Gigabyte", "curated_alias"),
    "samsung": ("Samsung", "curated_alias"),
    "philips": ("Philips", "curated_alias"),
    "panasonic": ("Panasonic", "curated_alias"),
    "hifiman": ("HiFiMAN", "curated_alias"),
    "keytronic": ("KeyTronic", "curated_alias"),
    "kensington": ("Kensington", "curated_alias"),
    "verbatim": ("Verbatim", "curated_alias"),
    "creative": ("Creative", "curated_alias"),
    "logitech g": ("Logitech", "curated_alias"),
    "alienware": ("Alienware", "curated_alias"),
    "monoprice": ("Monoprice", "curated_alias"),
    "sceptre": ("Sceptre", "curated_alias"),
    "hannspree": ("Hannspree", "curated_alias"),
    "asrock": ("ASRock", "curated_alias"),
    "acer": ("Acer", "curated_alias"),
    "asus": ("ASUS", "curated_alias"),
    "benq": ("BenQ", "curated_alias"),
    "dell": ("Dell", "curated_alias"),
    "aoc": ("AOC", "curated_alias"),
    "lg": ("LG", "curated_alias"),
    "msi": ("MSI", "curated_alias"),
    "hp": ("HP", "curated_alias"),
    "lenovo": ("Lenovo", "curated_alias"),
    "razer": ("Razer", "curated_alias"),
    "sony": ("Sony", "curated_alias"),
    "jbl": ("JBL", "curated_alias"),
    "shure": ("Shure", "curated_alias"),
    "akg": ("AKG", "curated_alias"),
    "koss": ("Koss", "curated_alias"),
    "yamaha": ("Yamaha", "curated_alias"),
    "ducky": ("Ducky", "curated_alias"),
    "cherry": ("Cherry", "curated_alias"),
    "roccat": ("ROCCAT", "curated_alias"),
    "pulsar": ("Pulsar", "curated_alias"),
    "pixio": ("Pixio", "curated_alias"),
    "koorui": ("KOORUI", "curated_alias"),
}

COMMON_COLUMNS = [
    "source_record_id", "source_code", "accessory_type", "raw_name", "brand",
    "model", "identity_key", "brand_confidence", "source_trust_status",
    "review_status", "recommendation_eligible", "completeness_score",
    "curation_priority", "candidate_use_tags", "warning_flags",
    "primary_use_profile", "source_variant_count", "available_colours",
    "source_offer_observed",
]


def clean_text(value: Any) -> str | None:
    if value is None or pd.isna(value):
        return None
    cleaned = re.sub(r"\s+", " ", str(value)).strip()
    return cleaned or None


def keyify(value: Any) -> str:
    text = clean_text(value) or ""
    return re.sub(r"[^a-z0-9]+", "-", text.casefold()).strip("-")


def number(value: Any) -> float | None:
    text = clean_text(value)
    if text is None:
        return None
    match = re.search(r"-?\d+(?:\.\d+)?", text.replace(",", ""))
    return float(match.group(0)) if match else None


def bounded_number(value: Any, minimum: float, maximum: float) -> tuple[float | None, bool]:
    parsed = number(value)
    if parsed is None:
        return None, False
    return (parsed, False) if minimum <= parsed <= maximum else (None, True)


def boolean(value: Any) -> bool | None:
    if value is None or pd.isna(value):
        return None
    if isinstance(value, bool):
        return value
    lowered = str(value).strip().casefold()
    if lowered in {"true", "yes", "1"}:
        return True
    if lowered in {"false", "no", "0"}:
        return False
    return None


def pair(value: Any) -> tuple[float | None, float | None]:
    text = clean_text(value)
    if text is None:
        return None, None
    values = [float(item) for item in re.findall(r"\d+(?:\.\d+)?", text)]
    return (values[0], values[1]) if len(values) >= 2 else (None, None)


def parse_brand_model(name: Any) -> tuple[str | None, str | None, str]:
    text = clean_text(name)
    if text is None:
        return None, None, "missing"
    lowered = text.casefold()
    for prefix in sorted(BRAND_PREFIXES, key=len, reverse=True):
        if lowered == prefix or lowered.startswith(prefix + " "):
            brand, confidence = BRAND_PREFIXES[prefix]
            model = text[len(prefix):].strip(" -")
            repeated = re.compile(rf"^{re.escape(brand)}\s+", re.I)
            model = repeated.sub("", model).strip()
            return brand, model or None, confidence
    parts = text.split(maxsplit=1)
    return parts[0], parts[1] if len(parts) > 1 else None, "inferred_first_token"


def normalize_connectivity(value: Any) -> str | None:
    text = (clean_text(value) or "").casefold()
    values: list[str] = []
    if "wired" in text and "wireless" not in text.replace("wireless", ""):
        values.append("wired")
    if "wireless" in text:
        values.append("wireless")
    if "bluetooth" in text:
        values.append("bluetooth")
    return ";".join(dict.fromkeys(values)) or None


def monitor_row(row: pd.Series) -> dict[str, Any]:
    warnings: list[str] = []
    size, bad_size = bounded_number(row.get("screen_size"), 10, 100)
    refresh, bad_refresh = bounded_number(row.get("refresh_rate"), 24, 1000)
    response, bad_response = bounded_number(row.get("response_time"), 0.01, 100)
    width, height = pair(row.get("resolution"))
    if width is not None and not (640 <= width <= 16384 and 480 <= (height or 0) <= 8640):
        width, height = None, None
        warnings.append("resolution_out_of_range")
    if bad_size:
        warnings.append("screen_size_out_of_range")
    if bad_refresh:
        warnings.append("refresh_rate_out_of_range")
    if bad_response:
        warnings.append("response_time_out_of_range")
    panel_raw = keyify(row.get("panel_type")).replace("-", "_") or None
    panel_map = {"qd_oled": "qd_oled", "mini_led": "mini_led", "ips": "ips", "va": "va", "tn": "tn", "oled": "oled"}
    panel = panel_map.get(panel_raw or "", "other" if panel_raw else None)
    aspect_raw = clean_text(row.get("aspect_ratio"))
    aspect = {"16:9": "16_9", "16:10": "16_10", "21:9": "21_9", "32:9": "32_9"}.get(aspect_raw, "other" if aspect_raw else None)
    tags = ["general"]
    if refresh is not None and refresh >= 144 and response is not None and response <= 2:
        tags.append("competitive_gaming")
    elif refresh is not None and refresh >= 120:
        tags.append("gaming")
    if width is not None and width >= 2560:
        tags.append("productivity")
    if width is not None and width >= 3840 and panel in {"ips", "oled", "qd_oled"}:
        tags.append("visual_creative")
    if "visual_creative" in tags:
        primary_use = "visual_creative"
    elif "competitive_gaming" in tags:
        primary_use = "competitive_gaming"
    elif "gaming" in tags:
        primary_use = "gaming"
    elif "productivity" in tags:
        primary_use = "productivity"
    else:
        primary_use = "general"
    return {
        "screen_size_inches": size,
        "resolution_width_pixels": int(width) if width is not None else None,
        "resolution_height_pixels": int(height) if height is not None else None,
        "refresh_rate_hz": refresh,
        "response_time_ms": response,
        "panel_type": panel,
        "aspect_ratio": aspect,
        "candidate_use_tags": ";".join(tags),
        "primary_use_profile": primary_use,
        "_warnings": warnings,
        "_required": ["screen_size_inches", "resolution_width_pixels", "resolution_height_pixels", "refresh_rate_hz", "panel_type"],
    }


def keyboard_row(row: pd.Series) -> dict[str, Any]:
    style = clean_text(row.get("style"))
    style_key = keyify(style)
    tenkeyless = boolean(row.get("tenkeyless"))
    if tenkeyless is True:
        keyboard_size = "tenkeyless"
    elif "mini" in style_key:
        keyboard_size = "compact"
    elif "ergonomic" in style_key:
        keyboard_size = "ergonomic"
    elif "standard" in style_key:
        keyboard_size = "full_size"
    else:
        keyboard_size = None
    switches = clean_text(row.get("switches"))
    switch_key = keyify(switches)
    if not switches:
        switch_technology = None
    elif "optical" in switch_key:
        switch_technology = "optical"
    elif "hall" in switch_key or "magnetic" in switch_key:
        switch_technology = "hall_effect"
    elif "membrane" in switch_key or "rubber-dome" in switch_key:
        switch_technology = "membrane"
    elif "scissor" in switch_key:
        switch_technology = "scissor"
    else:
        switch_technology = "mechanical"
    backlit = (clean_text(row.get("backlit")) or "").casefold()
    backlight = "rgb" if "rgb" in backlit else ("single_colour" if backlit else None)
    connectivity = normalize_connectivity(row.get("connection_type"))
    tags = ["general"]
    if "gaming" in style_key:
        tags.append("gaming")
    if keyboard_size in {"compact", "tenkeyless"} or (connectivity and "wireless" in connectivity):
        tags.append("portable")
    if keyboard_size == "ergonomic":
        tags.append("productivity")
        tags.append("ergonomic")
    if "ergonomic" in tags:
        primary_use = "ergonomic"
    elif "gaming" in tags:
        primary_use = "gaming"
    elif "portable" in tags:
        primary_use = "portable"
    else:
        primary_use = "productivity"
    return {
        "keyboard_style_raw": style,
        "keyboard_size": keyboard_size,
        "switch_technology": switch_technology,
        "switch_model": switches,
        "backlight_type": backlight,
        "connectivity": connectivity,
        "candidate_use_tags": ";".join(tags),
        "primary_use_profile": primary_use,
        "_warnings": [],
        "_required": ["keyboard_size", "connectivity"],
    }


def mouse_row(row: pd.Series) -> dict[str, Any]:
    warnings: list[str] = []
    tracking_key = keyify(row.get("tracking_method"))
    tracking = tracking_key if tracking_key in {"optical", "laser", "trackball"} else ("other" if tracking_key else None)
    connectivity = normalize_connectivity(row.get("connection_type"))
    dpi, bad_dpi = bounded_number(row.get("max_dpi"), 100, 100000)
    if bad_dpi:
        warnings.append("max_dpi_out_of_range")
    hand_key = keyify(row.get("hand_orientation"))
    hand = {"right": "right", "left": "left", "both": "ambidextrous", "ambidextrous": "ambidextrous"}.get(hand_key, "other" if hand_key else None)
    tags = ["general"]
    if dpi is not None and dpi >= 8000:
        tags.append("gaming")
    if connectivity and ("wireless" in connectivity or "bluetooth" in connectivity):
        tags.append("portable")
    if hand in {"left", "ambidextrous"}:
        tags.append("accessibility")
    if "accessibility" in tags:
        primary_use = "accessibility"
    elif "gaming" in tags:
        primary_use = "gaming"
    elif "portable" in tags:
        primary_use = "portable"
    else:
        primary_use = "productivity"
    return {
        "tracking_method": tracking,
        "connectivity": connectivity,
        "max_dpi": int(dpi) if dpi is not None else None,
        "hand_orientation": hand,
        "candidate_use_tags": ";".join(tags),
        "primary_use_profile": primary_use,
        "_warnings": warnings,
        "_required": ["tracking_method", "connectivity", "max_dpi", "hand_orientation"],
    }


def headset_row(row: pd.Series) -> dict[str, Any]:
    type_key = keyify(row.get("type"))
    style = {
        "circumaural": "over_ear",
        "supra-aural": "on_ear",
        "earbud": "in_ear",
        "in-ear": "in_ear",
    }.get(type_key, "other" if type_key else None)
    microphone = boolean(row.get("microphone"))
    wireless = boolean(row.get("wireless"))
    enclosure_key = keyify(row.get("enclosure_type"))
    enclosure = {"closed": "closed", "open": "open", "semi-open": "semi_open"}.get(enclosure_key, "other" if enclosure_key else None)
    frequency_raw = clean_text(row.get("frequency_response"))
    warnings = ["frequency_units_ambiguous"] if frequency_raw else []
    tags = ["general"]
    if microphone:
        tags.append("communication")
    if microphone and enclosure == "closed":
        tags.append("gaming")
    if enclosure == "open":
        tags.append("music_creation")
    if wireless:
        tags.append("portable")
    if enclosure == "open":
        primary_use = "music_creation"
    elif microphone and wireless:
        primary_use = "communication"
    elif microphone and enclosure == "closed":
        primary_use = "gaming"
    elif wireless:
        primary_use = "portable"
    else:
        primary_use = "communication"
    return {
        "headset_style": style,
        "has_microphone": microphone,
        "wireless_capable": wireless,
        "enclosure_type": enclosure,
        "frequency_response_raw": frequency_raw,
        "candidate_use_tags": ";".join(tags),
        "primary_use_profile": primary_use,
        "_warnings": warnings,
        "_required": ["headset_style", "has_microphone", "wireless_capable", "enclosure_type"],
    }


NORMALIZERS: dict[str, Callable[[pd.Series], dict[str, Any]]] = {
    "monitor": monitor_row,
    "keyboard": keyboard_row,
    "mouse": mouse_row,
    "headset": headset_row,
}


def stable_id(category: str, raw_name: str, row_number: int) -> str:
    value = f"{SOURCE_CODE}|{category}|{raw_name}|{row_number}"
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:20]


def completeness(record: dict[str, Any], required: list[str]) -> float:
    identity = sum(record.get(field) not in {None, ""} for field in ("brand", "model")) / 2
    required_score = sum(record.get(field) not in {None, ""} for field in required) / max(len(required), 1)
    return round((identity * 30) + (required_score * 70), 1)


def curation_bonus(category: str, record: dict[str, Any]) -> float:
    """Rank the manual-review queue without treating the source as truth."""
    bonus = 5 if record.get("source_offer_observed") else 0
    preferred = CATEGORY_PREFERRED_BRANDS.get(category, [])
    if record.get("brand") in preferred:
        bonus += 10
    model = str(record.get("model") or "")
    if len(re.findall(r"[A-Za-z]", model)) < 3:
        bonus -= 8
    if category == "monitor":
        size = record.get("screen_size_inches")
        if size is not None and 23 <= float(size) <= 34:
            bonus += 5
        if (record.get("resolution_height_pixels") or 0) >= 1080:
            bonus += 3
    elif category == "keyboard":
        if record.get("switch_technology"):
            bonus += 4
        if record.get("keyboard_size"):
            bonus += 3
    elif category == "mouse":
        if record.get("max_dpi"):
            bonus += 4
        if record.get("connectivity"):
            bonus += 3
    elif category == "headset":
        if record.get("has_microphone") is True:
            bonus += 7
        if record.get("enclosure_type") == "closed":
            bonus += 3
    return bonus


def normalize_category(category: str, source: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame, dict[str, Any]]:
    records: list[dict[str, Any]] = []
    rejected: list[dict[str, Any]] = []
    source = source.copy()
    exact_duplicate_rows = int(source.duplicated().sum())

    for row_number, row in source.iterrows():
        raw_name = clean_text(row.get("name"))
        brand, model, brand_confidence = parse_brand_model(raw_name)
        if not raw_name or not brand or not model:
            rejected.append({"accessory_type": category, "raw_name": raw_name, "reason": "identity_unparseable"})
            continue
        details = NORMALIZERS[category](row)
        required = details.pop("_required")
        warnings = details.pop("_warnings")
        colour = clean_text(row.get("color"))
        identity_key = f"{category}:{keyify(brand)}:{keyify(model)}"
        record: dict[str, Any] = {
            "source_record_id": stable_id(category, raw_name, int(row_number)),
            "source_code": SOURCE_CODE,
            "accessory_type": category,
            "raw_name": raw_name,
            "brand": brand,
            "model": model,
            "identity_key": identity_key,
            "brand_confidence": brand_confidence,
            "source_trust_status": SOURCE_TRUST_STATUS,
            "review_status": "needs_review",
            "recommendation_eligible": False,
            "source_variant_count": 1,
            "available_colours": colour,
            "source_offer_observed": clean_text(row.get("price")) is not None,
            **details,
        }
        missing = [field for field in required if record.get(field) in {None, ""}]
        warnings.extend(f"missing_{field}" for field in missing)
        warnings.append("unverified_reference_source")
        record["completeness_score"] = completeness(record, required)
        record["curation_priority"] = round(
            record["completeness_score"]
            + (5 if brand_confidence == "curated_alias" else 0)
            + curation_bonus(category, record)
            - (10 if any("out_of_range" in warning for warning in warnings) else 0),
            1,
        )
        record["warning_flags"] = ";".join(dict.fromkeys(warnings))
        records.append(record)

    frame = pd.DataFrame(records)
    if frame.empty:
        return frame, pd.DataFrame(rejected), {"raw_rows": len(source), "normalized_rows": 0}

    merged: list[dict[str, Any]] = []
    for _, group in frame.groupby("identity_key", sort=True):
        winner = group.sort_values(
            ["completeness_score", "brand_confidence"], ascending=[False, True]
        ).iloc[0].to_dict()
        colours = sorted({
            colour.strip()
            for value in group["available_colours"].dropna()
            for colour in str(value).split("/")
            if colour.strip()
        })
        winner["source_variant_count"] = int(len(group))
        winner["available_colours"] = ";".join(colours) or None
        if len(group) > 1:
            flags = [flag for flag in str(winner["warning_flags"]).split(";") if flag]
            flags.append("identity_duplicates_collapsed")
            winner["warning_flags"] = ";".join(dict.fromkeys(flags))
        merged.append(winner)

    normalized = pd.DataFrame(merged).sort_values(
        ["curation_priority", "brand", "model"], ascending=[False, True, True]
    )
    audit = {
        "raw_rows": int(len(source)),
        "exact_duplicate_rows": exact_duplicate_rows,
        "rejected_rows": int(len(rejected)),
        "normalized_canonical_candidates": int(len(normalized)),
        "identity_duplicates_collapsed": int(len(frame) - len(normalized)),
        "recommendation_eligible_rows": int(normalized["recommendation_eligible"].sum()),
        "average_completeness_score": round(float(normalized["completeness_score"].mean()), 2),
    }
    return normalized, pd.DataFrame(rejected), audit


def select_shortlist(
    frame: pd.DataFrame,
    target: int,
    max_per_brand: int = 3,
    category: str | None = None,
) -> pd.DataFrame:
    candidates = frame[
        (frame["brand_confidence"] == "curated_alias")
        & (frame["completeness_score"] >= 75)
        & ~frame["warning_flags"].str.contains("out_of_range", na=False)
    ].sort_values(["curation_priority", "brand", "model"], ascending=[False, True, True])
    if category is not None:
        preferred = CATEGORY_PREFERRED_BRANDS[category]
        candidates = candidates[
            candidates["brand"].isin(preferred)
            & candidates["source_offer_observed"].astype(bool)
        ]
        if category == "monitor":
            candidates = candidates[candidates["screen_size_inches"].between(21.5, 49)]
        elif category == "keyboard":
            candidates = candidates[
                ~candidates["model"].str.contains(
                    r"\b(?:tablet|cover|case|replacement)\b", case=False, regex=True, na=False
                )
            ]
        elif category == "headset":
            candidates = candidates[candidates["has_microphone"] == True]  # noqa: E712

    selected: list[int] = []
    brand_counts: dict[str, int] = {}
    brand_order = CATEGORY_PREFERRED_BRANDS.get(category, list(candidates["brand"].drop_duplicates()))
    profile_order = PROFILE_ORDER.get(category or "", [])

    # First balance the human verification queue across purposes. These are
    # deterministic candidate labels, not learned performance claims.
    while profile_order and len(selected) < target:
        progress = False
        for profile in profile_order:
            profile_rows = candidates[
                (candidates["primary_use_profile"] == profile)
                & ~candidates.index.isin(selected)
            ]
            for index, row in profile_rows.iterrows():
                brand = str(row["brand"])
                if brand_counts.get(brand, 0) >= max_per_brand:
                    continue
                selected.append(int(index))
                brand_counts[brand] = brand_counts.get(brand, 0) + 1
                progress = True
                break
            if len(selected) == target:
                break
        if not progress:
            break

    # Then round-robin brands to fill any remaining places.
    for round_number in range(max_per_brand):
        if len(selected) >= target:
            break
        for brand in brand_order:
            if len(selected) >= target:
                break
            if brand_counts.get(brand, 0) >= max_per_brand:
                continue
            brand_rows = candidates[
                (candidates["brand"] == brand) & ~candidates.index.isin(selected)
            ]
            if brand_rows.empty:
                continue
            index = int(brand_rows.index[0])
            selected.append(index)
            brand_counts[brand] = brand_counts.get(brand, 0) + 1
            if len(selected) == target:
                break
        if len(selected) == target:
            break
    return candidates.loc[selected].copy() if selected else candidates.head(0).copy()


def run_pipeline(raw_dir: Path, output_dir: Path) -> dict[str, Any]:
    output_dir.mkdir(parents=True, exist_ok=True)
    all_rejected: list[pd.DataFrame] = []
    all_shortlists: list[pd.DataFrame] = []
    audit: dict[str, Any] = {
        "pipeline_version": PIPELINE_VERSION,
        "source_code": SOURCE_CODE,
        "source_trust_status": SOURCE_TRUST_STATUS,
        "recommendation_eligibility_policy": "All Step 2 rows remain needs_review and recommendation_eligible=false.",
        "categories": {},
    }

    for category, filename in CATEGORY_FILES.items():
        source_path = raw_dir / filename
        source = pd.read_csv(source_path)
        normalized, rejected, category_audit = normalize_category(category, source)
        output_path = output_dir / f"{category}_candidates_clean.csv"
        normalized.to_csv(output_path, index=False)
        shortlist = select_shortlist(
            normalized, SHORTLIST_TARGETS[category], category=category
        )
        shortlist["shortlist_rank"] = range(1, len(shortlist) + 1)
        all_shortlists.append(shortlist)
        if not rejected.empty:
            all_rejected.append(rejected)
        category_audit["shortlist_needs_verification"] = int(len(shortlist))
        category_audit["shortlist_profile_counts"] = {
            str(key): int(value)
            for key, value in shortlist["primary_use_profile"].value_counts().items()
        }
        category_audit["output"] = output_path.name
        audit["categories"][category] = category_audit

    shortlist_frame = pd.concat(all_shortlists, ignore_index=True, sort=False)
    shortlist_frame.to_csv(output_dir / "peripheral_shortlist_needs_verification.csv", index=False)
    rejected_frame = pd.concat(all_rejected, ignore_index=True, sort=False) if all_rejected else pd.DataFrame(columns=["accessory_type", "raw_name", "reason"])
    rejected_frame.to_csv(output_dir / "peripheral_rejected_rows.csv", index=False)
    audit["totals"] = {
        "raw_rows": sum(item["raw_rows"] for item in audit["categories"].values()),
        "normalized_canonical_candidates": sum(item["normalized_canonical_candidates"] for item in audit["categories"].values()),
        "rejected_rows": sum(item["rejected_rows"] for item in audit["categories"].values()),
        "shortlist_needs_verification": int(len(shortlist_frame)),
        "recommendation_eligible_rows": 0,
    }
    (output_dir / "peripheral_normalization_audit.json").write_text(
        json.dumps(audit, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    return audit


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--raw-dir", type=Path, default=DEFAULT_RAW_DIR)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    parser.add_argument("--schema", type=Path, default=DEFAULT_SCHEMA)
    args = parser.parse_args()
    json.loads(args.schema.read_text(encoding="utf-8"))
    audit = run_pipeline(args.raw_dir, args.output_dir)
    print(json.dumps(audit, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
