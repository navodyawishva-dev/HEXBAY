import { useEffect, useMemo, useRef, useState } from "react";
import { apiRequest } from "../api/client";

const componentLabels = {
  processor: "Processor",
  motherboard: "Motherboard",
  memory: "Memory",
  graphics_card: "Graphics card",
  power_supply: "Power supply",
  storage: "Storage",
  computer_case: "Computer case",
  cpu_cooler: "CPU cooler",
};

const scoreLabels = {
  performance: "Workload performance",
  value: "Value",
  efficiency: "Efficiency",
  upgradeability: "Upgradeability",
  balance: "Build balance",
  budget_fit: "Budget fit",
};

function formatMoney(value) {
  return new Intl.NumberFormat("en-LK", {
    style: "currency",
    currency: "LKR",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);
}

function initialForm(search) {
  const params = new URLSearchParams(search);
  const workloads = (params.get("workloads") ?? "balanced_general")
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean)
    .slice(0, 3);
  return {
    target_budget_lkr: params.get("target_budget_lkr") ?? "300000",
    max_budget_lkr: params.get("max_budget_lkr") ?? "",
    flexibility_percent: params.get("flexibility_percent") ?? "7.5",
    workloads,
    dedicated_graphics: params.get("dedicated_graphics") ?? "auto",
    minimum_memory_gb: params.get("minimum_memory_gb") ?? "0",
    minimum_storage_gb: params.get("minimum_storage_gb") ?? "0",
  };
}

function ScoreBar({ label, value }) {
  const score = Math.max(0, Math.min(100, Number(value) || 0));
  return (
    <div className="pc-score-row">
      <div>
        <span>{label}</span>
        <strong>{score.toFixed(1)}</strong>
      </div>
      <div className="pc-score-track" aria-hidden="true">
        <span style={{ width: `${score}%` }} />
      </div>
    </div>
  );
}

function BudgetBadge({ tier }) {
  const labels = {
    within_target: "Within target",
    stretch: "Worthwhile stretch",
    nearest_available: "Nearest available",
  };
  return <span className={`pc-budget-badge is-${tier}`}>{labels[tier] ?? tier}</span>;
}

function BuildSummaryCard({ build, active, onInspect }) {
  const cpu = build.components.processor;
  const gpu = build.components.graphics_card;
  return (
    <article className={`pc-build-card ${active ? "is-active" : ""}`}>
      <div className="pc-build-card-topline">
        <BudgetBadge tier={build.budget_tier} />
        <span className={`pc-compatibility-pill is-${build.compatibility.status}`}>
          {build.compatibility.status}
        </span>
      </div>
      <h3>{build.label}</h3>
      <strong className="pc-build-total">{formatMoney(build.total_price_lkr)}</strong>
      <p className={build.target_difference_lkr > 0 ? "is-over" : "is-under"}>
        {build.target_difference_lkr > 0 ? "+" : ""}
        {formatMoney(build.target_difference_lkr)} from target
      </p>
      <dl className="pc-build-highlights">
        <div>
          <dt>CPU</dt>
          <dd>{cpu?.name ?? "Not required"}</dd>
        </div>
        <div>
          <dt>Graphics</dt>
          <dd>{gpu?.name ?? "Integrated graphics"}</dd>
        </div>
        <div>
          <dt>Performance</dt>
          <dd>{Number(build.scores.performance).toFixed(1)} / 100</dd>
        </div>
        <div>
          <dt>Value</dt>
          <dd>{Number(build.scores.value).toFixed(1)} / 100</dd>
        </div>
      </dl>
      <button className="button button-primary full-button" type="button" onClick={onInspect}>
        Inspect and customize
      </button>
    </article>
  );
}

function ComparisonTable({ builds }) {
  if (builds.length < 2) return null;
  const rows = [
    ["Total", (build) => formatMoney(build.total_price_lkr)],
    ["Budget tier", (build) => <BudgetBadge tier={build.budget_tier} />],
    ["Performance", (build) => Number(build.scores.performance).toFixed(1)],
    ["Value", (build) => Number(build.scores.value).toFixed(1)],
    ["Upgradeability", (build) => Number(build.scores.upgradeability).toFixed(1)],
    ["Processor", (build) => build.components.processor?.name ?? "—"],
    ["Graphics", (build) => build.components.graphics_card?.name ?? "Integrated"],
    ["Memory", (build) => build.components.memory?.name ?? "—"],
    ["Storage", (build) => build.components.storage?.name ?? "—"],
  ];
  return (
    <section className="pc-comparison-panel" aria-labelledby="pc-comparison-title">
      <div className="pc-panel-heading">
        <div>
          <span className="section-kicker">Trade-offs at a glance</span>
          <h2 id="pc-comparison-title">Compare recommended builds</h2>
        </div>
        <span>{builds.length} compatible options</span>
      </div>
      <div className="pc-comparison-scroll">
        <table>
          <thead>
            <tr>
              <th scope="col">Measure</th>
              {builds.map((build) => (
                <th scope="col" key={`head-${build.rank}`}>
                  Build {build.rank}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map(([label, value]) => (
              <tr key={label}>
                <th scope="row">{label}</th>
                {builds.map((build) => (
                  <td key={`${label}-${build.rank}`}>{value(build)}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

export default function PcBuilderPage({ navigate, search = "" }) {
  const startingForm = useRef(initialForm(search));
  const [form, setForm] = useState(startingForm.current);
  const [workloads, setWorkloads] = useState([]);
  const [result, setResult] = useState(null);
  const [activeRank, setActiveRank] = useState(1);
  const [lockedComponents, setLockedComponents] = useState({});
  const [loading, setLoading] = useState(false);
  const [loadingWorkloads, setLoadingWorkloads] = useState(true);
  const [error, setError] = useState("");
  const autoRun = useRef(new URLSearchParams(search).get("autorun") === "1");

  const requestPayload = (values = form, locks = lockedComponents) => ({
    target_budget_lkr: Number(values.target_budget_lkr),
    ...(values.max_budget_lkr
      ? { max_budget_lkr: Number(values.max_budget_lkr) }
      : { flexibility_percent: Number(values.flexibility_percent) }),
    workloads: values.workloads,
    preferences: {
      dedicated_graphics: values.dedicated_graphics,
      minimum_memory_gb: Number(values.minimum_memory_gb),
      minimum_storage_gb: Number(values.minimum_storage_gb),
    },
    locked_components: locks,
    limit: 3,
  });

  const generate = async (values = form, locks = lockedComponents) => {
    if (!values.workloads.length) {
      setError("Choose at least one main use case.");
      return;
    }
    setLoading(true);
    setError("");
    try {
      const response = await apiRequest("/pc-builder/recommendations", {
        method: "POST",
        body: requestPayload(values, locks),
      });
      const recommendation = response.data.recommendation;
      setResult(recommendation);
      setActiveRank(recommendation.recommendations?.[0]?.rank ?? 1);
      window.requestAnimationFrame(() => {
        document.getElementById("pc-builder-results")?.scrollIntoView({
          behavior: "smooth",
          block: "start",
        });
      });
    } catch (requestError) {
      setError(requestError.message || "The PC Builder could not generate a build.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    apiRequest("/pc-builder/workloads")
      .then((response) => {
        setWorkloads(response.data.workloads ?? []);
        if (autoRun.current) {
          autoRun.current = false;
          generate(startingForm.current, {});
        }
      })
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoadingWorkloads(false));
    // Initial URL state intentionally runs once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const activeBuild = useMemo(
    () => result?.recommendations?.find((build) => build.rank === activeRank)
      ?? result?.recommendations?.[0]
      ?? null,
    [activeRank, result]
  );

  const toggleWorkload = (code) => {
    setForm((current) => {
      const selected = current.workloads.includes(code);
      if (!selected && current.workloads.length >= 3) {
        setError("Choose no more than three use cases. Put the main one first.");
        return current;
      }
      setError("");
      return {
        ...current,
        workloads: selected
          ? current.workloads.filter((item) => item !== code)
          : current.workloads.length === 1
              && current.workloads[0] === "balanced_general"
              && code !== "balanced_general"
            ? [code]
            : [...current.workloads, code],
      };
    });
  };

  const toggleLock = (field, productId) => {
    setLockedComponents((current) => {
      if (current[field] === productId) {
        const next = { ...current };
        delete next[field];
        return next;
      }
      return { ...current, [field]: productId };
    });
  };

  const reset = () => {
    const defaults = initialForm("");
    setForm(defaults);
    setLockedComponents({});
    setResult(null);
    setError("");
    setActiveRank(1);
  };

  return (
    <div className="pc-builder-page">
      <section className="pc-builder-hero">
        <div>
          <span className="section-kicker">HEXBAY intelligent PC Builder</span>
          <h1>A complete PC around your real budget.</h1>
          <p>
            Tell us the budget and what the PC should do. HEXBAY handles platform,
            power, cooling and physical compatibility automatically using live
            marketplace stock.
          </p>
          <div className="pc-builder-hero-actions">
            <button
              className="button button-primary"
              type="button"
              onClick={() => document.getElementById("pc-builder-form")?.scrollIntoView({ behavior: "smooth" })}
            >
              Start a build
            </button>
            <button
              className="button pc-button-on-dark"
              type="button"
              onClick={() => window.dispatchEvent(new Event("hexbay:open-hexbot"))}
            >
              Ask HexBot instead
            </button>
          </div>
          <div className="pc-hero-proof">
            <span>18 use cases</span>
            <span>22 compatibility rules</span>
            <span>Live approved offers</span>
          </div>
        </div>
        <div className="pc-builder-diagram" aria-label="Budget, workload and compatibility combine into a balanced PC build">
          <div className="pc-diagram-core">HEXBAY</div>
          <div className="pc-diagram-node node-budget"><small>01</small> Budget</div>
          <div className="pc-diagram-node node-use"><small>02</small> Main use</div>
          <div className="pc-diagram-node node-rules"><small>03</small> Compatibility</div>
          <div className="pc-diagram-result">Balanced build</div>
        </div>
      </section>

      <section className="pc-builder-workspace" id="pc-builder-form">
        <form
          className="pc-builder-form"
          onSubmit={(event) => {
            event.preventDefault();
            setLockedComponents({});
            generate(form, {});
          }}
        >
          <div className="pc-form-heading">
            <div>
              <span className="section-kicker">Three meaningful choices</span>
              <h2>Describe the PC—not the socket.</h2>
              <p>Technical part matching stays inside the engine.</p>
            </div>
            <button className="button button-ghost" type="button" onClick={reset}>
              Reset
            </button>
          </div>

          <fieldset className="pc-form-section">
            <legend><span>1</span> Set the budget</legend>
            <div className="pc-budget-inputs">
              <label>
                Target budget (LKR)
                <input
                  type="number"
                  min="50000"
                  max="10000000"
                  step="1000"
                  required
                  value={form.target_budget_lkr}
                  onChange={(event) => setForm({ ...form, target_budget_lkr: event.target.value })}
                />
              </label>
              <label>
                Maximum ceiling (optional)
                <input
                  type="number"
                  min={form.target_budget_lkr || 50000}
                  step="1000"
                  placeholder="Use smart flexibility"
                  value={form.max_budget_lkr}
                  onChange={(event) => setForm({ ...form, max_budget_lkr: event.target.value })}
                />
              </label>
            </div>
            {!form.max_budget_lkr && (
              <label className="pc-flexibility-control">
                <span>
                  Smart stretch allowance
                  <strong>{form.flexibility_percent}%</strong>
                </span>
                <input
                  type="range"
                  min="0"
                  max="20"
                  step="2.5"
                  value={form.flexibility_percent}
                  onChange={(event) => setForm({ ...form, flexibility_percent: event.target.value })}
                />
                <small>
                  A stretch option appears only when the extra cost gives a measurable improvement.
                </small>
              </label>
            )}
          </fieldset>

          <fieldset className="pc-form-section">
            <legend><span>2</span> Choose the main use</legend>
            <p className="pc-field-help">Select up to three. The first selection is treated as the main focus.</p>
            {loadingWorkloads ? (
              <div className="pc-workload-loading">Loading use cases…</div>
            ) : (
              <div className="pc-workload-grid">
                {workloads.map((workload) => {
                  const selected = form.workloads.includes(workload.code);
                  const position = form.workloads.indexOf(workload.code);
                  return (
                    <button
                      type="button"
                      className={selected ? "is-selected" : ""}
                      aria-pressed={selected}
                      onClick={() => toggleWorkload(workload.code)}
                      key={workload.code}
                    >
                      {selected && <span>{position === 0 ? "Main" : `#${position + 1}`}</span>}
                      <strong>{workload.name}</strong>
                      <small>{workload.description}</small>
                    </button>
                  );
                })}
              </div>
            )}
          </fieldset>

          <fieldset className="pc-form-section pc-major-preferences">
            <legend><span>3</span> Optional major preferences</legend>
            <p className="pc-field-help">Leave these on automatic unless they genuinely matter to you.</p>
            <div className="pc-preference-grid">
              <label>
                Dedicated graphics
                <select
                  value={form.dedicated_graphics}
                  onChange={(event) => setForm({ ...form, dedicated_graphics: event.target.value })}
                >
                  <option value="auto">Choose for my workload</option>
                  <option value="required">Required</option>
                  <option value="avoid">Avoid to save budget/power</option>
                </select>
              </label>
              <label>
                Minimum memory
                <select
                  value={form.minimum_memory_gb}
                  onChange={(event) => setForm({ ...form, minimum_memory_gb: event.target.value })}
                >
                  <option value="0">Use workload recommendation</option>
                  <option value="16">At least 16 GB</option>
                  <option value="32">At least 32 GB</option>
                  <option value="64">At least 64 GB</option>
                </select>
              </label>
              <label>
                Minimum storage
                <select
                  value={form.minimum_storage_gb}
                  onChange={(event) => setForm({ ...form, minimum_storage_gb: event.target.value })}
                >
                  <option value="0">Use workload recommendation</option>
                  <option value="500">At least 500 GB</option>
                  <option value="1000">At least 1 TB</option>
                  <option value="2000">At least 2 TB</option>
                </select>
              </label>
            </div>
          </fieldset>

          {error && <div className="alert alert-error" role="alert">{error}</div>}
          <button className="button button-primary pc-generate-button" disabled={loading || loadingWorkloads}>
            {loading ? "Evaluating compatible builds…" : "Generate my PC builds"}
          </button>
          <small className="pc-form-scope">
            Component totals exclude delivery, assembly, peripherals and operating-system licences.
          </small>
        </form>
      </section>

      {(result || loading) && (
        <section className="pc-builder-results" id="pc-builder-results" aria-live="polite">
          {loading ? (
            <div className="pc-results-loading">
              <span />
              <h2>Balancing performance, price and compatibility…</h2>
              <p>Checking live offers and pruning unsafe combinations.</p>
            </div>
          ) : (
            <>
              <div className={`pc-outcome-banner is-${result.outcome_status}`}>
                <div>
                  <span className="section-kicker">Recommendation outcome</span>
                  <h2>
                    {result.outcome_status === "recommended" && "Compatible choices around your target"}
                    {result.outcome_status === "stretch_only" && "The best valid choice needs a small stretch"}
                    {result.outcome_status === "nearest_only" && "Current catalogue gap found"}
                    {result.outcome_status === "no_solution" && "No safe complete build found"}
                  </h2>
                  <p>{result.notice ?? "Only complete compatible builds are shown."}</p>
                </div>
                <dl>
                  <div><dt>Target</dt><dd>{formatMoney(result.budget_analysis.target_budget_lkr)}</dd></div>
                  <div><dt>Ceiling</dt><dd>{formatMoney(result.budget_analysis.max_budget_lkr)}</dd></div>
                  <div><dt>Minimum viable</dt><dd>{result.budget_analysis.minimum_viable_budget_lkr ? formatMoney(result.budget_analysis.minimum_viable_budget_lkr) : "Unavailable"}</dd></div>
                </dl>
              </div>

              {result.recommendations.length > 0 ? (
                <>
                  <div className="pc-build-card-grid">
                    {result.recommendations.map((build) => (
                      <BuildSummaryCard
                        build={build}
                        active={activeBuild?.rank === build.rank}
                        onInspect={() => {
                          setActiveRank(build.rank);
                          setLockedComponents({});
                          window.requestAnimationFrame(() => document.getElementById("pc-active-build")?.scrollIntoView({ behavior: "smooth" }));
                        }}
                        key={build.rank}
                      />
                    ))}
                  </div>

                  <ComparisonTable builds={result.recommendations} />

                  {activeBuild && (
                    <section className="pc-active-build" id="pc-active-build">
                      <div className="pc-active-build-heading">
                        <div>
                          <span className="section-kicker">Build {activeBuild.rank} details</span>
                          <h2>{activeBuild.label}</h2>
                          <p>{activeBuild.why_recommended.join(" ")}</p>
                        </div>
                        <div className="pc-active-total">
                          <small>Current component total</small>
                          <strong>{formatMoney(activeBuild.total_price_lkr)}</strong>
                          <span>{activeBuild.compatibility.passed} compatibility checks passed</span>
                        </div>
                      </div>

                      <div className="pc-active-layout">
                        <div className="pc-component-list">
                          {Object.entries(activeBuild.components).map(([field, component]) => {
                            const locked = lockedComponents[field] === component.product_id;
                            return (
                              <article className={locked ? "is-locked" : ""} key={field}>
                                <div className="pc-component-code">{componentLabels[field]?.slice(0, 3).toUpperCase()}</div>
                                <div>
                                  <span>{componentLabels[field]}</span>
                                  <h3>{component.name}</h3>
                                  <p>{component.shop_name} · {component.available_quantity} available</p>
                                  <small>{component.data_quality_status?.replaceAll("_", " ")}</small>
                                </div>
                                <div className="pc-component-actions">
                                  <strong>{formatMoney(component.price_lkr)}</strong>
                                  <button
                                    type="button"
                                    className={locked ? "is-locked" : ""}
                                    aria-pressed={locked}
                                    onClick={() => toggleLock(field, component.product_id)}
                                  >
                                    {locked ? "Locked ✓" : "Lock part"}
                                  </button>
                                  <button type="button" onClick={() => navigate(`/products/${component.product_id}`)}>
                                    View product
                                  </button>
                                </div>
                              </article>
                            );
                          })}
                        </div>

                        <aside className="pc-build-analysis">
                          <div className="pc-analysis-card">
                            <h3>Why this build works</h3>
                            {Object.entries(scoreLabels).map(([code, label]) => (
                              <ScoreBar label={label} value={activeBuild.scores[code]} key={code} />
                            ))}
                          </div>
                          <div className="pc-analysis-card">
                            <h3>Compatibility</h3>
                            <p className={`pc-compatibility-summary is-${activeBuild.compatibility.status}`}>
                              <strong>{activeBuild.compatibility.status}</strong>
                              <span>{activeBuild.compatibility.passed} passed · {activeBuild.compatibility.warnings} warnings</span>
                            </p>
                            <p>Validated with {activeBuild.compatibility.rule_set_version}.</p>
                          </div>
                          <div className="pc-analysis-card">
                            <h3>Trade-offs</h3>
                            {activeBuild.trade_offs.length ? (
                              <ul>{activeBuild.trade_offs.map((item) => <li key={item}>{item}</li>)}</ul>
                            ) : <p>No material trade-off was identified.</p>}
                          </div>
                        </aside>
                      </div>

                      <div className="pc-refinement-bar">
                        <div>
                          <strong>{Object.keys(lockedComponents).length} components locked</strong>
                          <span>Keep the parts you like and let HEXBAY rebuild everything else.</span>
                        </div>
                        <button
                          className="button button-primary"
                          type="button"
                          disabled={loading || Object.keys(lockedComponents).length === 0}
                          onClick={() => generate(form, lockedComponents)}
                        >
                          Rebuild around locked parts
                        </button>
                        <button className="button button-ghost" type="button" onClick={() => setLockedComponents({})}>
                          Clear locks
                        </button>
                      </div>

                      <details className="pc-requirements-details">
                        <summary>See {activeBuild.requirements.evaluations.length} workload requirement checks</summary>
                        <div>
                          {activeBuild.requirements.evaluations.map((requirement, index) => (
                            <article key={`${requirement.workload}-${requirement.metric}-${index}`}>
                              <span className={`is-${requirement.status}`}>{requirement.status.replaceAll("_", " ")}</span>
                              <strong>{requirement.metric.replaceAll("_", " ")}</strong>
                              <p>{requirement.rationale}</p>
                              <small>Actual {requirement.actual ?? "unknown"} · Recommended {requirement.recommended ?? "—"}</small>
                            </article>
                          ))}
                        </div>
                      </details>
                    </section>
                  )}
                </>
              ) : (
                <div className="pc-no-build">
                  <h3>No unsafe substitute was generated.</h3>
                  <p>{result.notice}</p>
                  <button className="button button-primary" type="button" onClick={() => document.getElementById("pc-builder-form")?.scrollIntoView({ behavior: "smooth" })}>
                    Adjust the main requirements
                  </button>
                </div>
              )}
            </>
          )}
        </section>
      )}
    </div>
  );
}
