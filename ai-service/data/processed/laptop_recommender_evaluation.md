# HEXBAY Laptop Recommender Offline Evaluation

- Algorithm: `laptop-content-v1.0.0`
- Cleaned reference laptops: **1,228**
- Deterministic evaluation sample: **500**
- Scenarios: **4**
- Scenarios with results: **4**
- Repeatable scenarios: **4**
- Hard-constraint violations in returned results: **0**

The source prices use an evaluation-only monotonic scale. They are not an exchange-rate conversion and are not current Sri Lankan prices.

| Scenario | Eligible | Returned | Repeatable | Violations |
|---|---:|---:|:---:|---:|
| general_mid_budget | 90 | 5 | Yes | 0 |
| gaming_high_budget | 26 | 5 | Yes | 0 |
| portable_study | 25 | 5 | Yes | 0 |
| programming | 18 | 5 | Yes | 0 |

## Limitations

- There are no user relevance labels in the Kaggle dataset.
- No accuracy, precision, recall, or satisfaction claim is made.
- Live recommendations must use eligible candidates from HEXBAY MySQL.
