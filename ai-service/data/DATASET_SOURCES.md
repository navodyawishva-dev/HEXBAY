# HEXBAY Dataset Sources

Downloaded on 2026-07-31 for local inspection and Sprint 5 development.
The untouched archives and extracted files are stored under `data/raw/` and
are intentionally excluded from version control.

## Primary datasets

### Laptop Prices Dataset

- Kaggle source: https://www.kaggle.com/datasets/paperxd/laptop-prices-dataset
- Local folder: `raw/laptop-prices/`
- Licence shown by Kaggle: CC0: Public Domain
- Downloaded content: one CSV with 1,257 data rows and 16 columns
- Intended use: laptop specification normalization, filtering, similarity,
  and content-based recommendation experiments

### PC Components Dataset

- Kaggle source: https://www.kaggle.com/datasets/sudhanshuy17/pccomponents
- Local folder: `raw/pc-components/`
- Licence shown by Kaggle: CC0: Public Domain
- Downloaded content: nine CSV files covering CPU, GPU, RAM, SSD,
  motherboard, power supply, and computer cases
- Intended use: component catalogue exploration and preprocessing experiments
- Limitation: product names and prices alone are not sufficient evidence of
  hardware compatibility; HEXBAY must enforce compatibility using validated,
  structured specifications and deterministic rules

### CPU and GPU Stats

- Kaggle source: https://www.kaggle.com/datasets/baraazaid/cpu-and-gpu-stats
- Local folder: `raw/cpu-gpu-stats/`
- Licence shown by Kaggle: CC0: Public Domain
- Downloaded content: CPU CSV with 2,614 data rows and GPU CSV with 3,584
  data rows
- Intended use: structured CPU/GPU specification normalization, comparison,
  ranking features, and support for compatibility attributes

## Supplementary dataset

### GPU - CUDA, Metal, OpenCL, Vulkan Scores

- Kaggle source:
  https://www.kaggle.com/datasets/alanjo/gpu-scores-with-cuda-metal-opencl-vulkan
- Local folder: `raw/gpu-benchmarks-supplementary/`
- Licence shown by Kaggle: CC0: Public Domain
- Downloaded content: two CSV files containing GPU benchmark scores
- Intended use: optional performance-ranking experiments only

## Data authority

These are external reference datasets. They do not provide authoritative
HEXBAY stock, seller availability, or current Sri Lankan prices. Live product
offers, LKR prices, stock quantities, and seller status remain authoritative
in the HEXBAY MySQL database.

## Peripheral source programme

The monitor, keyboard, mouse, and optional-headset source decision is recorded
in `peripheral_source_manifest.json` and documented in
`../../docs/STEP1_PERIPHERAL_DATA_ACQUISITION.md`.

- Open Icecat is the preferred licensed source for product identity and specs,
  subject to free account registration and its brand/content permissions.
- The docyx PC Part Dataset is a quarantined candidate-discovery source only.
  Its scraped rows and USD prices are not trusted marketplace evidence.
- HEXBAY approved sellers remain the only authority for LKR price and stock.

The Step 2 normalized schema, deduplication rules, quality gates, and balanced
80-product review shortlist are documented in
`../../docs/STEP2_PERIPHERAL_CATEGORISATION.md`.

The Step 3 weak-label training boundary, hybrid scoring design, cross-validation
results, and production eligibility gate are documented in
`../../docs/STEP3_PERIPHERAL_HYBRID_RECOMMENDER.md`.
