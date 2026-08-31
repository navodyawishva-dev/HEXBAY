"""Reproducible raw-to-clean preparation for HEXBAY's Sprint 5 datasets.

The Kaggle files are reference data only. This module never treats their
prices as current HEXBAY prices and never treats free-form product names as
hardware compatibility evidence.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

import pandas as pd


PIPELINE_VERSION = "1.0.0"
SERVICE_ROOT = Path(__file__).resolve().parent
DEFAULT_RAW_DIR = SERVICE_ROOT / "data" / "raw"
DEFAULT_PROCESSED_DIR = SERVICE_ROOT / "data" / "processed"

DATASET_SOURCES = {
    "laptops": "paperxd/laptop-prices-dataset",
    "cpu_gpu_stats": "baraazaid/cpu-and-gpu-stats",
    "pc_components": "sudhanshuy17/pccomponents",
    "gpu_benchmarks": "alanjo/gpu-scores-with-cuda-metal-opencl-vulkan",
}


def _normalise_text(value: Any) -> Any:
    if value is None or pd.isna(value):
        return pd.NA
    cleaned = re.sub(r"\s+", " ", str(value)).strip()
    return cleaned if cleaned else pd.NA


def _normalise_key(value: Any) -> Any:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return pd.NA
    return re.sub(r"[^a-z0-9]+", " ", str(cleaned).casefold()).strip()


def _normalise_text_columns(frame: pd.DataFrame, columns: Iterable[str]) -> None:
    for column in columns:
        if column in frame.columns:
            frame[column] = frame[column].map(_normalise_text)


def _first_number(value: Any) -> float | None:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return None
    match = re.search(r"\d+(?:\.\d+)?", str(cleaned).replace(",", ""))
    return float(match.group(0)) if match else None


def _parse_reference_price(value: Any) -> float | None:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return None
    match = re.search(r"\d[\d,]*(?:\.\d+)?", str(cleaned))
    return float(match.group(0).replace(",", "")) if match else None


def _frequency_bounds_ghz(value: Any) -> tuple[float | None, float | None]:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return None, None
    numbers = [
        float(item)
        for item in re.findall(r"\d+(?:\.\d+)?", str(cleaned).replace(",", ""))
    ]
    if not numbers:
        return None, None
    factor = 0.001 if "mhz" in str(cleaned).casefold() else 1.0
    values = [number * factor for number in numbers[:2]]
    return values[0], values[-1]


def _frequency_mhz(value: Any) -> float | None:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned) or "shared" in str(cleaned).casefold():
        return None
    number = _first_number(cleaned)
    if number is None:
        return None
    return number * 1000 if "ghz" in str(cleaned).casefold() else number


def _parse_cores_threads(value: Any) -> tuple[int | None, int | None]:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return None, None
    numbers = [int(item) for item in re.findall(r"\d+", str(cleaned))]
    if not numbers:
        return None, None
    return numbers[0], numbers[1] if len(numbers) > 1 else None


def _parse_cache_mb(value: Any) -> float | None:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return None
    number = _first_number(cleaned)
    if number is None:
        return None
    lowered = str(cleaned).casefold()
    if "kb" in lowered:
        return number / 1024
    if "gb" in lowered:
        return number * 1024
    return number


def _parse_memory_gb(value: Any) -> float | None:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned) or "shared" in str(cleaned).casefold():
        return None
    match = re.search(r"(\d+(?:\.\d+)?)\s*(KB|MB|GB)", str(cleaned), re.I)
    if not match:
        return None
    number = float(match.group(1))
    unit = match.group(2).upper()
    if unit == "KB":
        return number / (1024 * 1024)
    if unit == "MB":
        return number / 1024
    return number


def _parse_memory_type(value: Any) -> Any:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return pd.NA
    match = re.search(
        r"\b(GDDR\dX?|DDR\d|HBM\d?|DRAM|SDRAM|SGRAM)\b", str(cleaned), re.I
    )
    return match.group(1).upper() if match else pd.NA


def _parse_release_date(value: Any) -> Any:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return pd.NaT
    without_ordinals = re.sub(r"(\d+)(st|nd|rd|th)", r"\1", str(cleaned))
    parsed = pd.to_datetime(without_ordinals, errors="coerce")
    return parsed


def _parse_shader_counts(value: Any) -> tuple[int | None, int | None, int | None]:
    cleaned = _normalise_text(value)
    if pd.isna(cleaned):
        return None, None, None
    numbers = [int(item) for item in re.findall(r"\d+", str(cleaned))]
    # Some historical rows contain four undocumented values. Do not guess
    # their meaning; only parse rows that match the documented three fields.
    if len(numbers) != 3:
        return None, None, None
    return numbers[0], numbers[1], numbers[2]


def _stable_ids(
    frame: pd.DataFrame, namespace: str, columns: Iterable[str]
) -> pd.Series:
    selected = list(columns)

    def make_id(row: pd.Series) -> str:
        payload = "|".join("" if pd.isna(row[column]) else str(row[column]) for column in selected)
        digest = hashlib.sha256(f"{namespace}|{payload}".encode("utf-8")).hexdigest()
        return digest[:20]

    return frame.apply(make_id, axis=1)


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def audit_csv(path: Path, raw_root: Path) -> dict[str, Any]:
    frame = pd.read_csv(path)
    null_counts = {str(column): int(count) for column, count in frame.isna().sum().items()}
    return {
        "path": path.relative_to(raw_root).as_posix(),
        "sha256": _sha256(path),
        "bytes": path.stat().st_size,
        "rows": int(len(frame)),
        "columns": [str(column) for column in frame.columns],
        "column_count": int(len(frame.columns)),
        "exact_duplicate_rows": int(frame.duplicated().sum()),
        "missing_values": null_counts,
    }


def clean_laptops(raw: pd.DataFrame) -> pd.DataFrame:
    frame = raw.rename(
        columns={
            "Company": "brand",
            "TypeName": "laptop_type",
            "Screen_Size": "screen_size_inches",
            "Resolution": "resolution",
            "Touchscreen": "touchscreen",
            "IPS": "ips_panel",
            "CPU": "cpu",
            "Clock_Speed": "cpu_clock_ghz",
            "RAM": "ram_gb",
            "GPU": "gpu",
            "HDD": "hdd_gb",
            "SSD": "ssd_gb",
            "Flash_Storage": "flash_storage_gb",
            "OpSys": "operating_system",
            "Weight": "weight_kg",
            "Price": "reference_price",
        }
    ).copy()

    required_columns = {
        "brand",
        "laptop_type",
        "screen_size_inches",
        "resolution",
        "touchscreen",
        "ips_panel",
        "cpu",
        "cpu_clock_ghz",
        "ram_gb",
        "gpu",
        "hdd_gb",
        "ssd_gb",
        "flash_storage_gb",
        "operating_system",
        "weight_kg",
        "reference_price",
    }
    missing = required_columns.difference(frame.columns)
    if missing:
        raise ValueError(f"Laptop dataset is missing columns: {sorted(missing)}")

    _normalise_text_columns(
        frame,
        [
            "brand",
            "laptop_type",
            "resolution",
            "touchscreen",
            "ips_panel",
            "cpu",
            "gpu",
            "operating_system",
        ],
    )
    for column in [
        "screen_size_inches",
        "cpu_clock_ghz",
        "ram_gb",
        "hdd_gb",
        "ssd_gb",
        "flash_storage_gb",
        "weight_kg",
        "reference_price",
    ]:
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    frame["screen_size_normalized_from_cm"] = frame["screen_size_inches"].between(
        30,
        60,
    )
    frame.loc[
        frame["screen_size_normalized_from_cm"],
        "screen_size_inches",
    ] = (
        frame.loc[
            frame["screen_size_normalized_from_cm"],
            "screen_size_inches",
        ]
        .div(2.54)
        .round(1)
    )
    frame.loc[
        ~frame["screen_size_inches"].between(8, 30),
        "screen_size_inches",
    ] = pd.NA
    frame.loc[~frame["weight_kg"].between(0.2, 15), "weight_kg"] = pd.NA
    for storage_column in ["hdd_gb", "ssd_gb", "flash_storage_gb"]:
        frame.loc[
            ~frame[storage_column].between(0, 8192),
            storage_column,
        ] = pd.NA

    frame["touchscreen"] = (
        frame["touchscreen"].astype("string").str.casefold().map({"yes": True, "no": False})
    )
    frame["ips_panel"] = (
        frame["ips_panel"].astype("string").str.casefold().map({"yes": True, "no": False})
    )
    resolution_parts = frame["resolution"].astype("string").str.extract(
        r"(?P<resolution_width>\d+)\s*x\s*(?P<resolution_height>\d+)",
        flags=re.I,
    )
    frame["resolution_width"] = pd.to_numeric(
        resolution_parts["resolution_width"], errors="coerce"
    ).astype("Int64")
    frame["resolution_height"] = pd.to_numeric(
        resolution_parts["resolution_height"], errors="coerce"
    ).astype("Int64")
    frame["storage_total_gb"] = (
        frame[["hdd_gb", "ssd_gb", "flash_storage_gb"]].fillna(0).sum(axis=1)
    )

    frame = frame.drop_duplicates().copy()
    frame = frame[
        frame["brand"].notna()
        & frame["cpu"].notna()
        & frame["gpu"].notna()
        & frame["ram_gb"].gt(0)
        & frame["storage_total_gb"].gt(0)
        & frame["storage_total_gb"].le(8192)
        & frame["reference_price"].gt(0)
    ].copy()
    frame["source_dataset"] = DATASET_SOURCES["laptops"]
    frame["source_currency"] = "unspecified"
    frame["reference_price_only"] = True
    identity_columns = [
        "brand",
        "laptop_type",
        "screen_size_inches",
        "screen_size_normalized_from_cm",
        "resolution",
        "touchscreen",
        "ips_panel",
        "cpu",
        "cpu_clock_ghz",
        "gpu",
        "ram_gb",
        "hdd_gb",
        "ssd_gb",
        "flash_storage_gb",
        "operating_system",
        "weight_kg",
        "reference_price",
    ]
    frame.insert(0, "source_record_id", _stable_ids(frame, "laptop", identity_columns))
    frame = frame.sort_values(
        ["brand", "laptop_type", "cpu", "ram_gb", "reference_price"],
        kind="stable",
    ).reset_index(drop=True)
    return frame[
        [
            "source_record_id",
            "brand",
            "laptop_type",
            "screen_size_inches",
            "screen_size_normalized_from_cm",
            "resolution",
            "resolution_width",
            "resolution_height",
            "touchscreen",
            "ips_panel",
            "cpu",
            "cpu_clock_ghz",
            "ram_gb",
            "gpu",
            "hdd_gb",
            "ssd_gb",
            "flash_storage_gb",
            "storage_total_gb",
            "operating_system",
            "weight_kg",
            "reference_price",
            "source_currency",
            "reference_price_only",
            "source_dataset",
        ]
    ]


def clean_cpu_stats(raw: pd.DataFrame) -> pd.DataFrame:
    frame = raw.drop(columns=["Unnamed: 0"], errors="ignore").rename(
        columns={
            "Name": "name",
            "Codename": "codename",
            "Cores": "cores_raw",
            "Clock": "clock_raw",
            "Socket": "socket_raw",
            "Process": "process_raw",
            "L3_Cache": "l3_cache_raw",
            "TDP": "tdp_raw",
            "Release": "release_raw",
        }
    )
    required = {
        "name",
        "codename",
        "cores_raw",
        "clock_raw",
        "socket_raw",
        "process_raw",
        "l3_cache_raw",
        "tdp_raw",
        "release_raw",
    }
    missing = required.difference(frame.columns)
    if missing:
        raise ValueError(f"CPU dataset is missing columns: {sorted(missing)}")

    frame = frame.copy()
    _normalise_text_columns(frame, required)
    frame = frame[
        frame["name"].notna()
        & ~frame["name"].str.contains(
            r"no cpus found", case=False, regex=True, na=False
        )
    ].copy()
    frame = frame.drop_duplicates().copy()

    core_pairs = frame["cores_raw"].map(_parse_cores_threads)
    frame["core_count"] = pd.array(
        [pair[0] for pair in core_pairs], dtype="Int64"
    )
    frame["thread_count"] = pd.array(
        [pair[1] for pair in core_pairs], dtype="Int64"
    )
    clock_pairs = frame["clock_raw"].map(_frequency_bounds_ghz)
    frame["base_clock_ghz"] = [pair[0] for pair in clock_pairs]
    frame["boost_clock_ghz"] = [pair[1] for pair in clock_pairs]
    frame["socket"] = (
        frame["socket_raw"]
        .astype("string")
        .str.replace(r"^socket\s+", "", case=False, regex=True)
        .str.strip()
        .str.upper()
    )
    frame["process_nm"] = frame["process_raw"].map(_first_number)
    frame["l3_cache_mb"] = frame["l3_cache_raw"].map(_parse_cache_mb)
    frame["tdp_w"] = frame["tdp_raw"].map(_first_number)
    frame["release_date"] = frame["release_raw"].map(_parse_release_date)
    frame["has_structured_specs"] = frame[
        ["core_count", "base_clock_ghz", "socket", "tdp_w"]
    ].notna().all(axis=1)
    frame["source_dataset"] = DATASET_SOURCES["cpu_gpu_stats"]
    identity_columns = [
        "name",
        "codename",
        "cores_raw",
        "clock_raw",
        "socket_raw",
        "process_raw",
        "l3_cache_raw",
        "tdp_raw",
        "release_raw",
    ]
    frame.insert(0, "source_record_id", _stable_ids(frame, "cpu", identity_columns))
    frame = frame.sort_values(["name", "release_date"], kind="stable").reset_index(
        drop=True
    )
    return frame[
        [
            "source_record_id",
            "name",
            "codename",
            "core_count",
            "thread_count",
            "base_clock_ghz",
            "boost_clock_ghz",
            "socket",
            "process_nm",
            "l3_cache_mb",
            "tdp_w",
            "release_date",
            "has_structured_specs",
            "cores_raw",
            "clock_raw",
            "socket_raw",
            "process_raw",
            "l3_cache_raw",
            "tdp_raw",
            "release_raw",
            "source_dataset",
        ]
    ]


def clean_gpu_stats(raw: pd.DataFrame) -> pd.DataFrame:
    frame = raw.drop(columns=["Unnamed: 0"], errors="ignore").rename(
        columns={
            "Product_Name": "name",
            "GPU_Chip": "gpu_chip",
            "Released": "release_raw",
            "Bus": "bus_interface",
            "Memory": "memory_raw",
            "GPU_clock": "gpu_clock_raw",
            "Memory_clock": "memory_clock_raw",
            "Shaders_TMUs_ROPs": "shader_counts_raw",
        }
    )
    required = {
        "name",
        "gpu_chip",
        "release_raw",
        "bus_interface",
        "memory_raw",
        "gpu_clock_raw",
        "memory_clock_raw",
        "shader_counts_raw",
    }
    missing = required.difference(frame.columns)
    if missing:
        raise ValueError(f"GPU dataset is missing columns: {sorted(missing)}")

    frame = frame.copy()
    _normalise_text_columns(frame, required)
    frame = frame[
        frame["name"].notna()
        & ~frame["name"].str.contains(
            r"no graphics cards found", case=False, regex=True, na=False
        )
    ].copy()
    frame = frame.drop_duplicates().copy()
    frame["release_date"] = frame["release_raw"].map(_parse_release_date)
    frame["memory_gb"] = frame["memory_raw"].map(_parse_memory_gb)
    frame["memory_type"] = frame["memory_raw"].map(_parse_memory_type)
    frame["gpu_clock_mhz"] = frame["gpu_clock_raw"].map(_frequency_mhz)
    frame["memory_clock_mhz"] = frame["memory_clock_raw"].map(_frequency_mhz)
    shader_counts = frame["shader_counts_raw"].map(_parse_shader_counts)
    frame["shader_count"] = pd.array(
        [counts[0] for counts in shader_counts], dtype="Int64"
    )
    frame["tmu_count"] = pd.array(
        [counts[1] for counts in shader_counts], dtype="Int64"
    )
    frame["rop_count"] = pd.array(
        [counts[2] for counts in shader_counts], dtype="Int64"
    )
    frame["has_structured_specs"] = frame[
        ["gpu_chip", "bus_interface", "gpu_clock_mhz"]
    ].notna().all(axis=1)
    frame["source_dataset"] = DATASET_SOURCES["cpu_gpu_stats"]
    identity_columns = [
        "name",
        "gpu_chip",
        "release_raw",
        "bus_interface",
        "memory_raw",
        "gpu_clock_raw",
        "memory_clock_raw",
        "shader_counts_raw",
    ]
    frame.insert(0, "source_record_id", _stable_ids(frame, "gpu", identity_columns))
    frame = frame.sort_values(["name", "release_date"], kind="stable").reset_index(
        drop=True
    )
    return frame[
        [
            "source_record_id",
            "name",
            "gpu_chip",
            "release_date",
            "bus_interface",
            "memory_gb",
            "memory_type",
            "gpu_clock_mhz",
            "memory_clock_mhz",
            "shader_count",
            "tmu_count",
            "rop_count",
            "has_structured_specs",
            "memory_raw",
            "gpu_clock_raw",
            "memory_clock_raw",
            "shader_counts_raw",
            "release_raw",
            "source_dataset",
        ]
    ]


PC_COMPONENT_FILES = (
    ("PcData/CPU.csv", "cpu", "CPU"),
    ("amd_cpus.csv", "cpu", "CPU"),
    ("intel_cpus.csv", "cpu", "CPU"),
    ("PcData/GPU.csv", "gpu", "GPU"),
    ("PcData/MotherBoard.csv", "motherboard", "motherBoard"),
    ("PcData/RAM.csv", "ram", "Ram"),
    ("PcData/StorageSSD.csv", "storage", "SSD"),
    ("PcData/PowerSupply.csv", "psu", "PowerSupply"),
    ("PcData/cabinates.csv", "case", "cabinates"),
)

PC_CATEGORY_PATTERNS = {
    "cpu": r"\b(processor|cpu|ryzen|athlon|celeron|pentium|xeon|core\s*[3579])\b",
    "gpu": r"\b(graphics card|geforce|radeon|quadro|\bgpu\b)\b",
    "motherboard": r"\b(motherboard|mainboard)\b",
    "ram": r"\b(ram|memory|ddr[2-5])\b",
    "storage": r"\b(ssd|solid state|nvme|sata|m\.?2)\b",
    "psu": r"\b(psu|power supply|smps|watts?)\b",
    "case": r"\b(computer case|gaming cabinet|cabinet|cabinate|tower|chassis)\b",
}


def clean_pc_components(raw_root: Path) -> pd.DataFrame:
    pieces: list[pd.DataFrame] = []
    for relative_path, category, product_column in PC_COMPONENT_FILES:
        path = raw_root / relative_path
        if not path.exists():
            raise FileNotFoundError(f"Required PC component file not found: {path}")
        raw = pd.read_csv(path)
        if product_column not in raw.columns or "MRP" not in raw.columns:
            raise ValueError(f"Unexpected columns in PC component file: {path}")
        piece = raw[[product_column, "MRP"]].rename(
            columns={product_column: "name", "MRP": "reference_price_raw"}
        )
        piece["category"] = category
        piece["source_file"] = relative_path
        pieces.append(piece)

    frame = pd.concat(pieces, ignore_index=True)
    _normalise_text_columns(frame, ["name", "reference_price_raw"])
    frame["name_key"] = frame["name"].map(_normalise_key)
    frame["reference_price_inr"] = frame["reference_price_raw"].map(
        _parse_reference_price
    )
    frame = frame[
        frame["name"].notna()
        & frame["name_key"].notna()
        & frame["reference_price_inr"].gt(0)
    ].copy()
    frame = frame.sort_values(
        ["category", "name_key", "reference_price_inr", "source_file"], kind="stable"
    )
    frame = frame.drop_duplicates(
        subset=["category", "name_key", "reference_price_inr"], keep="first"
    ).copy()
    frame["category_keyword_match"] = frame.apply(
        lambda row: bool(
            re.search(
                PC_CATEGORY_PATTERNS[str(row["category"])],
                str(row["name"]),
                flags=re.I,
            )
        ),
        axis=1,
    )
    frame["manual_review_required"] = ~frame["category_keyword_match"]
    frame["eligible_for_compatibility"] = False
    frame["reference_price_only"] = True
    frame["source_currency"] = "INR"
    frame["source_dataset"] = DATASET_SOURCES["pc_components"]
    identity_columns = ["category", "name_key", "reference_price_inr"]
    frame.insert(
        0, "source_record_id", _stable_ids(frame, "pc-component", identity_columns)
    )
    return frame[
        [
            "source_record_id",
            "category",
            "name",
            "reference_price_inr",
            "source_currency",
            "category_keyword_match",
            "manual_review_required",
            "eligible_for_compatibility",
            "reference_price_only",
            "source_file",
            "source_dataset",
        ]
    ].reset_index(drop=True)


def clean_gpu_benchmarks(raw_root: Path) -> pd.DataFrame:
    files = sorted(raw_root.glob("*.csv"))
    if not files:
        raise FileNotFoundError(f"No GPU benchmark CSV files found in {raw_root}")

    pieces: list[pd.DataFrame] = []
    for path in files:
        raw = pd.read_csv(path)
        raw.columns = [str(column).strip().casefold() for column in raw.columns]
        if "device" not in raw.columns:
            raise ValueError(f"GPU benchmark file has no Device column: {path}")
        for column in ["manufacturer", "cuda", "metal", "opencl", "vulkan"]:
            if column not in raw.columns:
                raw[column] = pd.NA
        piece = raw[["manufacturer", "device", "cuda", "metal", "opencl", "vulkan"]].copy()
        piece["source_file"] = path.name
        pieces.append(piece)

    frame = pd.concat(pieces, ignore_index=True)
    _normalise_text_columns(frame, ["manufacturer", "device"])
    for column in ["cuda", "metal", "opencl", "vulkan"]:
        frame[column] = pd.to_numeric(frame[column], errors="coerce")
    frame["device_key"] = frame["device"].map(_normalise_key)
    frame = frame[
        frame["device_key"].notna()
        & frame[["cuda", "metal", "opencl", "vulkan"]].notna().any(axis=1)
    ].copy()
    frame = frame.sort_values(
        ["device_key", "source_file", "manufacturer"], kind="stable"
    )
    frame = frame.drop_duplicates(
        subset=["manufacturer", "device_key", "cuda", "metal", "opencl", "vulkan"],
        keep="first",
    ).copy()
    frame["supplementary_only"] = True
    frame["eligible_for_compatibility"] = False
    frame["source_dataset"] = DATASET_SOURCES["gpu_benchmarks"]
    identity_columns = ["manufacturer", "device_key", "cuda", "metal", "opencl", "vulkan"]
    frame.insert(
        0, "source_record_id", _stable_ids(frame, "gpu-benchmark", identity_columns)
    )
    return frame[
        [
            "source_record_id",
            "manufacturer",
            "device",
            "cuda",
            "metal",
            "opencl",
            "vulkan",
            "supplementary_only",
            "eligible_for_compatibility",
            "source_file",
            "source_dataset",
        ]
    ].reset_index(drop=True)


def _write_csv(frame: pd.DataFrame, path: Path) -> None:
    temporary = path.with_suffix(path.suffix + ".tmp")
    frame.to_csv(temporary, index=False, date_format="%Y-%m-%d")
    temporary.replace(path)


def _write_json(payload: dict[str, Any], path: Path) -> None:
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(payload, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    temporary.replace(path)


def _audit_markdown(report: dict[str, Any]) -> str:
    processed = report["processed_outputs"]
    lines = [
        "# HEXBAY Sprint 5 Dataset Audit",
        "",
        f"- Pipeline version: `{report['pipeline_version']}`",
        f"- Generated: `{report['generated_at_utc']}`",
        f"- Raw CSV files inspected: **{len(report['raw_files'])}**",
        "",
        "## Processed outputs",
        "",
        "| Output | Rows | Columns |",
        "|---|---:|---:|",
    ]
    for name, details in processed.items():
        lines.append(
            f"| `{name}` | {details['rows']:,} | {details['columns']:,} |"
        )
    lines.extend(
        [
            "",
            "## Suitability decisions",
            "",
            "- **Laptop prices:** accepted for specification normalization and "
            "content-based recommendation experiments. The source price currency "
            "is not assumed, and those prices are never current HEXBAY prices.",
            "- **CPU/GPU specifications:** accepted as reference specifications "
            "after removing scraper sentinel rows and exact duplicates. Incomplete "
            "records remain visibly flagged.",
            "- **PC component listings:** retained only as noisy reference data. "
            "Free-form names and INR prices cannot establish compatibility or "
            "current Sri Lankan availability.",
            "- **GPU benchmark scores:** supplementary only. Scores from different "
            "graphics APIs are not treated as interchangeable measurements.",
            "",
            "## Authority boundary",
            "",
            "HEXBAY MySQL remains authoritative for approved products, sellers, "
            "LKR prices, stock and availability. Deterministic, validated fields "
            "remain authoritative for hardware compatibility.",
            "",
        ]
    )
    return "\n".join(lines)


def run_pipeline(
    raw_dir: Path = DEFAULT_RAW_DIR,
    processed_dir: Path = DEFAULT_PROCESSED_DIR,
) -> dict[str, Any]:
    raw_dir = raw_dir.resolve()
    processed_dir = processed_dir.resolve()
    if not raw_dir.exists():
        raise FileNotFoundError(f"Raw dataset directory does not exist: {raw_dir}")
    if raw_dir == processed_dir:
        raise ValueError("Raw and processed dataset directories must be different.")

    expected = {
        "laptops": raw_dir / "laptop-prices" / "laptop.csv",
        "cpus": raw_dir / "cpu-gpu-stats" / "tpu_cpus.csv",
        "gpus": raw_dir / "cpu-gpu-stats" / "tpu_gpus.csv",
        "pc_components": raw_dir / "pc-components",
        "gpu_benchmarks": raw_dir / "gpu-benchmarks-supplementary",
    }
    missing = [str(path) for path in expected.values() if not path.exists()]
    if missing:
        raise FileNotFoundError("Required raw dataset paths are missing: " + ", ".join(missing))

    processed_dir.mkdir(parents=True, exist_ok=True)
    raw_audit = [audit_csv(path, raw_dir) for path in sorted(raw_dir.rglob("*.csv"))]

    outputs = {
        "laptops_clean.csv": clean_laptops(pd.read_csv(expected["laptops"])),
        "cpus_clean.csv": clean_cpu_stats(pd.read_csv(expected["cpus"])),
        "gpus_clean.csv": clean_gpu_stats(pd.read_csv(expected["gpus"])),
        "pc_components_clean.csv": clean_pc_components(expected["pc_components"]),
        "gpu_benchmarks_clean.csv": clean_gpu_benchmarks(expected["gpu_benchmarks"]),
    }
    for filename, frame in outputs.items():
        _write_csv(frame, processed_dir / filename)

    processed_outputs: dict[str, Any] = {}
    for filename, frame in outputs.items():
        path = processed_dir / filename
        processed_outputs[filename] = {
            "rows": int(len(frame)),
            "columns": int(len(frame.columns)),
            "sha256": _sha256(path),
            "bytes": path.stat().st_size,
        }

    report = {
        "pipeline_version": PIPELINE_VERSION,
        "generated_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "raw_directory": str(raw_dir),
        "processed_directory": str(processed_dir),
        "raw_files": raw_audit,
        "processed_outputs": processed_outputs,
        "decisions": {
            "laptops": "accepted_for_content_based_experiments",
            "cpu_gpu_stats": "accepted_with_incomplete_records_flagged",
            "pc_components": "reference_only_manual_review_required",
            "gpu_benchmarks": "supplementary_only",
            "marketplace_authority": "mysql",
            "compatibility_authority": "validated_deterministic_rules",
        },
    }
    _write_json(report, processed_dir / "dataset_audit.json")
    (processed_dir / "dataset_audit.md").write_text(
        _audit_markdown(report), encoding="utf-8"
    )
    return report


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Audit and prepare HEXBAY's local Sprint 5 datasets."
    )
    parser.add_argument("--raw-dir", type=Path, default=DEFAULT_RAW_DIR)
    parser.add_argument("--processed-dir", type=Path, default=DEFAULT_PROCESSED_DIR)
    args = parser.parse_args()

    report = run_pipeline(args.raw_dir, args.processed_dir)
    print(
        f"Dataset preparation complete: {len(report['raw_files'])} raw CSV files "
        f"audited and {len(report['processed_outputs'])} clean CSV files generated."
    )
    for filename, details in report["processed_outputs"].items():
        print(f"- {filename}: {details['rows']} rows, {details['columns']} columns")
    print(f"Audit report: {Path(report['processed_directory']) / 'dataset_audit.md'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
