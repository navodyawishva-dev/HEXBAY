"""Acquire and audit raw sources selected for HEXBAY peripheral work.

This is a Step 1 provenance tool, not a training or database-import script.
Reference-only datasets are quarantined unless the caller explicitly opts in.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


SERVICE_ROOT = Path(__file__).resolve().parent
DEFAULT_MANIFEST = SERVICE_ROOT / "data" / "peripheral_source_manifest.json"
DEFAULT_RAW_DIR = SERVICE_ROOT / "data" / "raw" / "peripheral-step1"
DEFAULT_AUDIT = SERVICE_ROOT / "data" / "processed" / "peripheral_source_audit.json"
ALLOWED_DOWNLOAD_STATUSES = {"approved_reference"}
REFERENCE_ONLY_STATUS = "reference_only_needs_verification"


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def audit_csv(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        columns = reader.fieldnames or []
        rows = list(reader)

    serialized = [tuple(row.get(column, "") for column in columns) for row in rows]
    exact_duplicate_rows = len(serialized) - len(set(serialized))
    missing = {
        column: sum(not str(row.get(column, "")).strip() for row in rows)
        for column in columns
    }
    return {
        "format": "csv",
        "rows": len(rows),
        "columns": columns,
        "exact_duplicate_rows": exact_duplicate_rows,
        "missing_values": missing,
    }


def audit_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig") as handle:
        payload = json.load(handle)
    result: dict[str, Any] = {"format": "json"}
    if isinstance(payload, dict):
        result["top_level_keys"] = list(payload.keys())
        result["collection_counts"] = {
            key: len(value)
            for key, value in payload.items()
            if isinstance(value, list)
        }
    elif isinstance(payload, list):
        result["rows"] = len(payload)
    return result


def audit_file(path: Path, data_format: str) -> dict[str, Any]:
    details = audit_csv(path) if data_format == "csv" else audit_json(path)
    return {
        "path": path.as_posix(),
        "bytes": path.stat().st_size,
        "sha256": sha256_file(path),
        **details,
    }


def download(url: str, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "HEXBAY-Dataset-Audit/1.0"},
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        destination.write_bytes(response.read())


def acquire_sources(
    manifest_path: Path,
    raw_dir: Path,
    include_reference_only: bool = False,
    downloader=download,
) -> dict[str, Any]:
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    audit: dict[str, Any] = {
        "manifest_version": manifest["manifest_version"],
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "reference_only_included": include_reference_only,
        "sources": [],
    }

    for source in manifest["sources"]:
        status = source["trust_status"]
        allowed = status in ALLOWED_DOWNLOAD_STATUSES or (
            include_reference_only and status == REFERENCE_ONLY_STATUS
        )
        source_audit: dict[str, Any] = {
            "code": source["code"],
            "trust_status": status,
            "downloaded": [],
        }

        if allowed:
            for item in source.get("downloads", []):
                destination = raw_dir / item["path"]
                downloader(item["url"], destination)
                file_audit = audit_file(destination, item["format"])
                file_audit["url"] = item["url"]
                source_audit["downloaded"].append(file_audit)

        if not source_audit["downloaded"]:
            if status == REFERENCE_ONLY_STATUS and not include_reference_only:
                source_audit["note"] = (
                    "Skipped by trust gate. Use --include-reference-only to "
                    "quarantine it for discovery."
                )
            elif status == "approved_after_account_setup":
                source_audit["note"] = "Requires account credentials and a permitted product feed."
            elif status == "required_production_authority":
                source_audit["note"] = "Seller-managed database authority; no public download exists."
            else:
                source_audit["note"] = "No downloadable file is configured for this phase."
        audit["sources"].append(source_audit)

    return audit


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    parser.add_argument("--raw-dir", type=Path, default=DEFAULT_RAW_DIR)
    parser.add_argument("--audit-output", type=Path, default=DEFAULT_AUDIT)
    parser.add_argument(
        "--include-reference-only",
        action="store_true",
        help="Download quarantined discovery data; it remains ineligible for recommendations.",
    )
    args = parser.parse_args()

    audit = acquire_sources(
        args.manifest,
        args.raw_dir,
        include_reference_only=args.include_reference_only,
    )
    args.audit_output.parent.mkdir(parents=True, exist_ok=True)
    args.audit_output.write_text(
        json.dumps(audit, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(audit, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
