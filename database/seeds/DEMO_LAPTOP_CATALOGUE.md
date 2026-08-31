# HEXBAY demo laptop catalogue

This local-only fixture creates two approved demonstration shops, eight
canonical laptops, eleven active in-stock offers, complete recommendation
specifications and a product image for every offer.

Run it after importing the schema and applying migrations 002 and 003:

```powershell
C:\wamp64\bin\php\php8.3.6\php.exe -d xdebug.mode=off database\seeds\seed_demo_laptop_catalogue.php
```

The script is idempotent: running it again updates the same demonstration
accounts, shops, products, prices, stock and images instead of creating
duplicates.

Local seller accounts:

- `seller.metrotech@hexbay.test` / `DemoSeller123`
- `seller.bytehub@hexbay.test` / `DemoSeller123`

These are fictional local demonstration records. The generated photographs are
unbranded test assets and do not represent the named manufacturers' exact
products.

Suggested HexBot checks:

- `I need a study laptop under Rs. 250,000 with 16 GB RAM.`
- `Recommend a gaming laptop under Rs. 410,000 with RTX graphics.`
- `I need a creative-work laptop under Rs. 600,000 with 32 GB RAM and 1 TB storage.`

HexBot should ask only about missing requirements, confirm the resulting
profile, and show different live products for the three conversations.
