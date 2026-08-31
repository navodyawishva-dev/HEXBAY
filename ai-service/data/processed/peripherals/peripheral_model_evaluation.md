# HEXBAY Peripheral Hybrid Model Evaluation

- Algorithm: `peripheral-hybrid-v1.0.0`
- Reference candidates: **11,738**
- Production ready: **No**
- Mean weak-label accuracy: **0.9846**
- Mean weak-label macro F1: **0.9499**
- Mean five-fold weak-label accuracy: **0.9820**
- Mean five-fold weak-label macro F1: **0.9630**
- Mean weak precision@5: **1.0000**
- Mean weak NDCG@5: **1.0000**
- Deterministic ranking: **Pass**
- Unverified-data production gate: **Pass**
- Artifact hash/reload smoke tests: **Pass**

| Category | Rows | Holdout accuracy | 5-fold accuracy | 5-fold macro F1 | Precision@5 | NDCG@5 | Gate |
|---|---:|---:|---:|---:|---:|---:|:---:|
| Monitor | 4,786 | 0.9791 | 0.9743 | 0.9658 | 1.0000 | 1.0000 | Pass |
| Keyboard | 2,345 | 0.9915 | 0.9787 | 0.9843 | 1.0000 | 1.0000 | Pass |
| Mouse | 2,097 | 0.9857 | 0.9876 | 0.9848 | 1.0000 | 1.0000 | Pass |
| Headset | 2,510 | 0.9821 | 0.9873 | 0.9171 | 1.0000 | 1.0000 | Pass |

These scores measure agreement with deterministic candidate labels, not real customer satisfaction or objective product superiority.

## Limitations

- Purpose labels are deterministic weak labels, not customer relevance judgements.
- Accuracy and ranking metrics measure agreement with those weak labels only.
- All source products remain unverified and are blocked in production mode.
- Price, stock, warranty, delivery, and seller trust are intentionally absent from model training.
- Joblib artifacts must be loaded only from trusted local builds and checked against their recorded SHA-256 hashes.
