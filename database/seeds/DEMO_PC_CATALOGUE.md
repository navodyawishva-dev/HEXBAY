# HEXBAY demo PC-component catalogue

This local-only fixture creates two approved demonstration shops, 32 canonical
PC components, 49 active in-stock offers, structured compatibility data, a
product image for every offer, and a logo for each shop.

Run it after importing the schema and applying migrations 002 and 003:

```powershell
C:\wamp64\bin\php\php8.3.6\php.exe -d xdebug.mode=off database\seeds\seed_demo_pc_catalogue.php
```

The script is idempotent: running it again updates the same demonstration
accounts, shops, products, prices, stock, specifications, and images instead of
creating duplicates.

After applying migration 004 and running this catalogue seed, load the Step 1
knowledge foundation:

```powershell
C:\wamp64\bin\php\php8.3.6\php.exe -d xdebug.mode=off database\seeds\seed_pc_knowledge_foundation.php
```

The knowledge seed adds broad workload profiles, explainable normalized
performance/value inputs, provenance, data-quality status and price snapshots.
Its curated scores are evaluation fixtures and are deliberately marked as
needing independent review before production use.

Local seller accounts:

- `seller.novacore@hexbay.test` / `DemoSeller123`
- `seller.bytecraft@hexbay.test` / `DemoSeller123`

The catalogue covers AM4, AM5 and LGA1700 processors and CPU coolers; DDR4 and DDR5 boards and
memory; GPUs with different lengths, wattage recommendations and connectors;
ATX and SFX power supplies; PCIe NVMe storage; and cases with different
motherboard, GPU and PSU limits. This creates both compatible and deliberately
incompatible selections for the upcoming PC-builder rules.

These are fictional local demonstration offers. Prices are simulated Sri
Lankan rupee values for interface testing, and the unbranded generated images
do not represent each named manufacturer's exact product.
