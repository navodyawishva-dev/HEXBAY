import json
from pathlib import Path

from peripheral_source_pipeline import acquire_sources, audit_csv


def test_audit_csv_counts_duplicates_and_missing_values(tmp_path: Path) -> None:
    path = tmp_path / "items.csv"
    path.write_text(
        "name,price,dpi\nMouse A,10,8000\nMouse A,10,8000\nMouse B,,\n",
        encoding="utf-8",
    )

    audit = audit_csv(path)

    assert audit["rows"] == 3
    assert audit["exact_duplicate_rows"] == 1
    assert audit["missing_values"] == {"name": 0, "price": 1, "dpi": 1}


def test_reference_only_source_is_opt_in(tmp_path: Path) -> None:
    manifest = {
        "manifest_version": "test",
        "sources": [
            {
                "code": "approved",
                "trust_status": "approved_reference",
                "downloads": [
                    {"url": "memory://approved", "path": "approved.csv", "format": "csv"}
                ],
            },
            {
                "code": "quarantined",
                "trust_status": "reference_only_needs_verification",
                "downloads": [
                    {"url": "memory://quarantined", "path": "quarantined.csv", "format": "csv"}
                ],
            },
        ],
    }
    manifest_path = tmp_path / "manifest.json"
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

    def downloader(url: str, destination: Path) -> None:
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text("name\nExample\n", encoding="utf-8")

    audit = acquire_sources(manifest_path, tmp_path / "raw", downloader=downloader)

    assert len(audit["sources"][0]["downloaded"]) == 1
    assert audit["sources"][1]["downloaded"] == []
    assert not (tmp_path / "raw" / "quarantined.csv").exists()

    audit_with_reference = acquire_sources(
        manifest_path,
        tmp_path / "raw-with-reference",
        include_reference_only=True,
        downloader=downloader,
    )
    assert len(audit_with_reference["sources"][1]["downloaded"]) == 1
