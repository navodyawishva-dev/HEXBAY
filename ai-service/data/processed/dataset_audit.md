# HEXBAY Sprint 5 Dataset Audit

- Pipeline version: `1.0.0`
- Generated: `2026-07-31T09:47:09+00:00`
- Raw CSV files inspected: **14**

## Processed outputs

| Output | Rows | Columns |
|---|---:|---:|
| `laptops_clean.csv` | 1,228 | 24 |
| `cpus_clean.csv` | 2,538 | 21 |
| `gpus_clean.csv` | 2,333 | 19 |
| `pc_components_clean.csv` | 2,119 | 11 |
| `gpu_benchmarks_clean.csv` | 2,714 | 11 |

## Suitability decisions

- **Laptop prices:** accepted for specification normalization and content-based recommendation experiments. The source price currency is not assumed, and those prices are never current HEXBAY prices.
- **CPU/GPU specifications:** accepted as reference specifications after removing scraper sentinel rows and exact duplicates. Incomplete records remain visibly flagged.
- **PC component listings:** retained only as noisy reference data. Free-form names and INR prices cannot establish compatibility or current Sri Lankan availability.
- **GPU benchmark scores:** supplementary only. Scores from different graphics APIs are not treated as interchangeable measurements.

## Authority boundary

HEXBAY MySQL remains authoritative for approved products, sellers, LKR prices, stock and availability. Deterministic, validated fields remain authoritative for hardware compatibility.
