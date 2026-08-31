from pathlib import Path

import pandas as pd

from dataset_pipeline import (
    clean_cpu_stats,
    clean_laptops,
    clean_pc_components,
)


def test_clean_laptops_removes_duplicates_and_parses_fields():
    row = {
        "Company": " Test Brand ",
        "TypeName": "Notebook",
        "Screen_Size": 15.6,
        "Resolution": "1920x1080",
        "Touchscreen": "No",
        "IPS": "Yes",
        "CPU": "Intel Core i5",
        "Clock_Speed": 2.5,
        "RAM": 8,
        "GPU": "Intel Graphics",
        "HDD": 0,
        "SSD": 512,
        "Flash_Storage": 0,
        "OpSys": "Windows",
        "Weight": 1.8,
        "Price": 800.0,
    }

    cleaned = clean_laptops(pd.DataFrame([row, row]))

    assert len(cleaned) == 1
    assert cleaned.iloc[0]["brand"] == "Test Brand"
    assert cleaned.iloc[0]["resolution_width"] == 1920
    assert cleaned.iloc[0]["resolution_height"] == 1080
    assert bool(cleaned.iloc[0]["ips_panel"]) is True
    assert bool(cleaned.iloc[0]["touchscreen"]) is False
    assert cleaned.iloc[0]["storage_total_gb"] == 512
    assert cleaned.iloc[0]["source_currency"] == "unspecified"


def test_clean_laptops_normalizes_cm_and_rejects_impossible_storage():
    base = {
        "Company": "Test Brand",
        "TypeName": "Notebook",
        "Screen_Size": 35.6,
        "Resolution": "1920x1080",
        "Touchscreen": "No",
        "IPS": "Yes",
        "CPU": "Intel Core i5",
        "Clock_Speed": 2.5,
        "RAM": 8,
        "GPU": "Intel Graphics",
        "HDD": 0,
        "SSD": 512,
        "Flash_Storage": 0,
        "OpSys": "Windows",
        "Weight": 0.0002,
        "Price": 800.0,
    }
    impossible_storage = {
        **base,
        "Screen_Size": 15.6,
        "HDD": 1001000,
        "SSD": 0,
        "Weight": 2.0,
    }

    cleaned = clean_laptops(pd.DataFrame([base, impossible_storage]))

    assert len(cleaned) == 1
    assert cleaned.iloc[0]["screen_size_inches"] == 14.0
    assert bool(cleaned.iloc[0]["screen_size_normalized_from_cm"]) is True
    assert pd.isna(cleaned.iloc[0]["weight_kg"])


def test_clean_cpu_stats_removes_scraper_sentinel_and_parses_specs():
    raw = pd.DataFrame(
        [
            {
                "Unnamed: 0": 0,
                "Name": "No CPUs found. Please change your search criteria.",
                "Codename": None,
                "Cores": None,
                "Clock": None,
                "Socket": None,
                "Process": None,
                "L3_Cache": None,
                "TDP": None,
                "Release": None,
            },
            {
                "Unnamed: 0": 1,
                "Name": "Example CPU",
                "Codename": "Example",
                "Cores": "6 / 12",
                "Clock": "3.6 to 4.2 GHz",
                "Socket": "Socket AM4",
                "Process": "7 nm",
                "L3_Cache": "32 MB",
                "TDP": "65 W",
                "Release": "Jul 7th, 2019",
            },
        ]
    )

    cleaned = clean_cpu_stats(raw)

    assert len(cleaned) == 1
    assert cleaned.iloc[0]["name"] == "Example CPU"
    assert cleaned.iloc[0]["core_count"] == 6
    assert cleaned.iloc[0]["thread_count"] == 12
    assert cleaned.iloc[0]["base_clock_ghz"] == 3.6
    assert cleaned.iloc[0]["boost_clock_ghz"] == 4.2
    assert cleaned.iloc[0]["socket"] == "AM4"
    assert cleaned.iloc[0]["tdp_w"] == 65
    assert bool(cleaned.iloc[0]["has_structured_specs"]) is True


def test_clean_pc_components_parses_inr_and_deduplicates(tmp_path: Path):
    fixtures = {
        "PcData/CPU.csv": ("CPU", "AMD Ryzen processor"),
        "amd_cpus.csv": ("CPU", "AMD Ryzen processor"),
        "intel_cpus.csv": ("CPU", "Intel Core i5 processor"),
        "PcData/GPU.csv": ("GPU", "NVIDIA GeForce graphics card"),
        "PcData/MotherBoard.csv": ("motherBoard", "ASUS AM4 motherboard"),
        "PcData/RAM.csv": ("Ram", "Kingston DDR4 RAM"),
        "PcData/StorageSSD.csv": ("SSD", "Samsung NVMe SSD"),
        "PcData/PowerSupply.csv": ("PowerSupply", "Corsair 650 Watts PSU"),
        "PcData/cabinates.csv": ("cabinates", "ATX gaming cabinet"),
    }
    for relative_path, (column, value) in fixtures.items():
        path = tmp_path / relative_path
        path.parent.mkdir(parents=True, exist_ok=True)
        pd.DataFrame([{column: value, "MRP": "₹12,499"}]).to_csv(path, index=False)

    cleaned = clean_pc_components(tmp_path)

    assert len(cleaned) == 8
    assert set(cleaned["category"]) == {
        "cpu",
        "gpu",
        "motherboard",
        "ram",
        "storage",
        "psu",
        "case",
    }
    assert cleaned["reference_price_inr"].eq(12499).all()
    assert cleaned["reference_price_only"].all()
    assert not cleaned["eligible_for_compatibility"].any()
