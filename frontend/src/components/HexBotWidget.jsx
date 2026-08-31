import { useCallback, useEffect, useRef, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import idleImage from "../assets/hexbot/production-v2/hexbot-idle-v2.webp";
import waveImage from "../assets/hexbot/production-v2/hexbot-wave-v2.webp";
import sleepImage from "../assets/hexbot/production-v2/hexbot-sleep-v2.webp";
import thinkImage from "../assets/hexbot/production-v2/hexbot-think-v2.webp";
import happyImage from "../assets/hexbot/production-v2/hexbot-happy-v2.webp";

const SESSION_KEY_STORAGE = "hexbay_hexbot_session_key";
const WORKSPACE_STORAGE = "hexbay_hexbot_workspace";
const FIVE_MINUTES = 5 * 60 * 1000;
const mascotImages = {
  idle: idleImage,
  wave: waveImage,
  sleep: sleepImage,
  thinking: thinkImage,
  talking: idleImage,
  happy: happyImage,
};

const pcComponentLabels = {
  processor: "Processor",
  motherboard: "Motherboard",
  memory: "Memory",
  graphics_card: "Graphics card",
  power_supply: "Power supply",
  storage: "Storage",
  computer_case: "Computer case",
  cpu_cooler: "CPU cooler",
};

const pcScoreLabels = {
  performance: "Performance",
  value: "Value",
  upgradeability: "Upgradeability",
  budget_fit: "Budget fit",
};

const peripheralLabels = {
  monitor: "Monitor",
  keyboard: "Keyboard",
  mouse: "Mouse",
  headset: "Headset",
};

function createSessionKey() {
  const randomPart =
    typeof window.crypto?.randomUUID === "function"
      ? window.crypto.randomUUID().replaceAll("-", "")
      : `${Date.now()}${Math.random().toString(36).slice(2)}`;
  return `hexbot_${randomPart}`;
}

function getSessionKey() {
  window.localStorage.removeItem(SESSION_KEY_STORAGE);
  let key = window.sessionStorage.getItem(SESSION_KEY_STORAGE);
  if (!key) {
    key = createSessionKey();
    window.sessionStorage.setItem(SESSION_KEY_STORAGE, key);
  }
  return key;
}

function getStoredWorkspace() {
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(WORKSPACE_STORAGE) ?? "{}");
    return stored && typeof stored === "object" ? stored : {};
  } catch {
    return {};
  }
}

function formatMoney(value) {
  return new Intl.NumberFormat("en-LK", {
    style: "currency",
    currency: "LKR",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);
}

function Mascot({ state, className = "" }) {
  const current = mascotImages[state] ? state : "idle";
  return (
    <img
      className={`hexbot-mascot hexbot-mascot-${current} ${className}`}
      src={mascotImages[current]}
      alt={`HexBot is ${current}`}
      draggable="false"
    />
  );
}

function RecommendationCard({ item, navigate, alternative = false }) {
  const image = item.image_filename
    ? mediaUrl("product-images", item.image_filename)
    : "";
  return (
    <article className="hexbot-recommendation-card">
      {image ? (
        <img src={image} alt="" />
      ) : (
        <div className="hexbot-product-placeholder" aria-hidden="true">
          H
        </div>
      )}
      <div>
        <span
          className={`hexbot-match ${alternative ? "is-alternative" : ""}`}
        >
          {alternative
            ? "Gaming-capable alternative"
            : `${Math.round((Number(item.score) || 0) * 100)}% match`}
        </span>
        <h4>{item.name}</h4>
        <p>
          {item.brand} · {Number(item.ram_gb)} GB RAM ·{" "}
          {Number(item.storage_gb)} GB storage
        </p>
        <strong>{formatMoney(item.price_lkr)}</strong>
        {item.reasons?.length > 0 && (
          <ul>
            {item.reasons.slice(0, 2).map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
        )}
        <button
          type="button"
          onClick={() => navigate(`/products/${item.product_id}`)}
        >
          View product
        </button>
      </div>
    </article>
  );
}

function LaptopAccessoryCard({ category, item, navigate }) {
  return (
    <article className="x-board-laptop-accessory-card">
      <div className="x-board-accessory-code" aria-hidden="true">
        {(peripheralLabels[category] ?? category).slice(0, 3).toUpperCase()}
      </div>
      <div>
        <span>{peripheralLabels[category] ?? category}</span>
        <h4>{item.name}</h4>
        <p>
          {item.brand} · {item.profile?.replaceAll("_", " ")} fit ·{" "}
          {Number(item.fit_score ?? 0).toFixed(0)}/100
        </p>
        {item.reasons?.[0] && <small>{item.reasons[0]}</small>}
      </div>
      <div>
        <strong>{formatMoney(item.price_lkr)}</strong>
        <small>{item.shop_name}</small>
        <button type="button" onClick={() => navigate(`/products/${item.product_id}`)}>
          View product
        </button>
      </div>
    </article>
  );
}

function ComparisonProductCard({ product, verdict, navigate, onAction }) {
  const image = product.image_filename
    ? mediaUrl("product-images", product.image_filename)
    : "";
  const isListedLeader = Number(verdict?.listed_advantage_product_id) === Number(product.product_id);
  const isLowerPrice = Number(verdict?.lower_price_product_id) === Number(product.product_id);
  return (
    <article className="x-board-comparison-product">
      {image ? <img src={image} alt="" /> : <div aria-hidden="true">{product.brand?.slice(0, 1)}</div>}
      <section>
        <span>{product.brand} · {product.category}</span>
        <h4>{product.name}</h4>
        <p>{product.model}</p>
        <strong>{formatMoney(product.price_lkr)}</strong>
        <small>{product.shop_name} · {product.available_quantity} available</small>
        <div>
          {isListedLeader && <em>More listed advantages</em>}
          {isLowerPrice && <em>Lower live price</em>}
        </div>
        <div className="x-board-comparison-product-actions">
          <button type="button" onClick={() => navigate(`/products/${product.product_id}`)}>View product</button>
          {product.pc_component_group && (
            <button
              type="button"
              className="is-secondary"
              onClick={() => onAction(
                `Use ${product.name} in a PC build`,
                `compare:use-in-pc:${product.product_id}`
              )}
            >
              Use in PC build
            </button>
          )}
        </div>
      </section>
    </article>
  );
}

function PcBuildCard({ build, onOpen }) {
  const cpu = build.components?.processor;
  const gpu = build.components?.graphics_card;
  return (
    <article className="hexbot-pc-build-card">
      <div>
        <span className={`hexbot-pc-tier is-${build.budget_tier}`}>
          {build.budget_tier?.replaceAll("_", " ")}
        </span>
        <span className={`hexbot-pc-status is-${build.compatibility?.status}`}>
          {build.compatibility?.status}
        </span>
      </div>
      <h4>{build.label}</h4>
      <strong>{formatMoney(build.total_price_lkr)}</strong>
      <dl>
        <div><dt>CPU</dt><dd>{cpu?.name ?? "—"}</dd></div>
        <div><dt>GPU</dt><dd>{gpu?.name ?? "Integrated graphics"}</dd></div>
      </dl>
      <p>
        Performance {Number(build.scores?.performance ?? 0).toFixed(1)} · Value{" "}
        {Number(build.scores?.value ?? 0).toFixed(1)}
      </p>
      <button type="button" onClick={onOpen}>
        Open in X Board
      </button>
    </article>
  );
}

function XBoardWorkspace({
  pcBuilds,
  pcBudgetAnalysis,
  recommendations,
  laptopAccessories,
  technicalAnswer,
  productComparison,
  eligibleCount,
  activePcRank,
  setActivePcRank,
  navigate,
  onPrompt,
  workspaceRef,
  user,
  token,
  pcRecommendationId,
  pcBuilderRequest,
}) {
  const [offerSelections, setOfferSelections] = useState({});
  const [cartState, setCartState] = useState({ loading: false, error: "", receipt: null });
  const activeBuild = pcBuilds.find((build) => build.rank === activePcRank)
    ?? pcBuilds[0]
    ?? null;
  useEffect(() => {
    setCartState({ loading: false, error: "", receipt: null });
  }, [activeBuild]);
  const selectedOffer = (item, key) => {
    const offers = Array.isArray(item?.offers) ? item.offers : [];
    const listingId = Number(offerSelections[key] ?? item?.listing_id);
    return offers.find((offer) => Number(offer.listing_id) === listingId)
      ?? offers[0]
      ?? item;
  };
  const pcEntries = Object.entries(activeBuild?.components ?? {});
  const peripheralEntries = Object.entries(activeBuild?.peripherals ?? {});
  const laptopAccessoryEntries = Object.entries(laptopAccessories?.peripherals ?? {});
  const requestedLaptopAccessories = Array.isArray(laptopAccessories?.requested_categories)
    ? laptopAccessories.requested_categories
    : [];
  const laptopAccessoryNotices = Array.isArray(laptopAccessories?.notices)
    ? laptopAccessories.notices
    : [];
  const peripheralCodes = peripheralEntries.map(([field]) => field);
  const hasCoreSetup = ["monitor", "keyboard", "mouse"].every((field) =>
    peripheralCodes.includes(field));
  const peripheralHeading = hasCoreSetup
    ? "Complete setup"
    : peripheralCodes.map((field) => peripheralLabels[field] ?? field).join(" + ");
  const selectedPcTotal = pcEntries.reduce((total, [field, component]) => (
    total + Number(selectedOffer(component, `${activeBuild?.rank}:pc:${field}`)?.price_lkr ?? 0)
  ), 0);
  const selectedPeripheralTotal = peripheralEntries.reduce((total, [field, component]) => (
    total + Number(selectedOffer(component, `${activeBuild?.rank}:peripheral:${field}`)?.price_lkr ?? 0)
  ), 0);
  const selectedTotal = activeBuild
    ? selectedPcTotal + selectedPeripheralTotal
    : 0;
  const targetBudget = Number(pcBudgetAnalysis?.target_budget_lkr ?? 0);
  const maximumBudget = Number(pcBudgetAnalysis?.max_budget_lkr ?? targetBudget);
  const selectedBudgetPosition = selectedTotal <= targetBudget
    ? "Within target"
    : selectedTotal <= maximumBudget ? "Worthwhile stretch" : "Above ceiling";
  const decisionReasons = (activeBuild?.why_recommended ?? []).filter(
    (reason) => !/^(Keeps LKR|Costs LKR)/.test(reason)
  );
  if (activeBuild) {
    decisionReasons.push(
      selectedTotal <= targetBudget
        ? `Current seller choices keep ${formatMoney(targetBudget - selectedTotal)} unspent.`
        : `Current seller choices are ${formatMoney(selectedTotal - targetBudget)} above target.`
    );
  }
  const chooseOffer = (key, listingId) => {
    setOfferSelections((current) => ({ ...current, [key]: Number(listingId) }));
    setCartState({ loading: false, error: "", receipt: null });
  };
  const selectedSetupItems = [
    ...pcEntries.map(([field, component]) => ({
      ...selectedOffer(component, `${activeBuild?.rank}:pc:${field}`),
      component_group: "pc",
      component_code: field,
    })),
    ...peripheralEntries.map(([field, component]) => ({
      ...selectedOffer(component, `${activeBuild?.rank}:peripheral:${field}`),
      component_group: "peripheral",
      component_code: field,
    })),
  ].filter((offer) => Number(offer?.listing_id) > 0);
  const addSetupToCart = async () => {
    if (!user) {
      window.sessionStorage.setItem("hexbay_post_login_path", "/x-board");
      navigate("/login");
      return;
    }
    if (user.role !== "customer") {
      setCartState({
        loading: false,
        error: "A buyer account is required to purchase this setup.",
        receipt: null,
      });
      return;
    }
    if (selectedSetupItems.length !== pcEntries.length + peripheralEntries.length) {
      setCartState({
        loading: false,
        error: "One or more products no longer has a selectable seller offer. Ask HexBot to refresh the build.",
        receipt: null,
      });
      return;
    }
    setCartState({ loading: true, error: "", receipt: null });
    try {
      const response = await apiRequest("/cart/setup", {
        method: "POST",
        token,
        body: {
          items: selectedSetupItems.map((offer) => ({
            listing_id: Number(offer.listing_id),
            quantity: 1,
            expected_price_lkr: Number(offer.price_lkr),
            component_group: offer.component_group,
            component_code: offer.component_code,
          })),
          setup: {
            source_recommendation_id: pcRecommendationId || null,
            build_rank: Number(activeBuild.rank),
            setup_scope: activeBuild.setup_scope || "pc_only",
            target_budget_lkr: targetBudget,
            max_budget_lkr: maximumBudget,
            requirements: pcBuilderRequest || {},
            scores: activeBuild.scores || {},
            compatibility: activeBuild.compatibility || {},
          },
        },
      });
      setCartState({ loading: false, error: "", receipt: response.data.setup });
    } catch (requestError) {
      setCartState({
        loading: false,
        error: requestError.message || "The complete setup could not be added.",
        receipt: null,
      });
    }
  };

  return (
    <section ref={workspaceRef} className="x-board-workspace" aria-label="HexBot recommendation workspace">
      <header className="x-board-workspace-header">
        <div>
          <span>X BOARD · LIVE WORKSPACE</span>
          <h2>Your recommendations, fully explained.</h2>
          <p>Talk to HexBot on the left. Every confirmed requirement and result appears here.</p>
        </div>
        <div className="x-board-engine-status">
          <i aria-hidden="true" /> Compatibility engine online
        </div>
      </header>

      {!activeBuild && recommendations.length === 0 && !technicalAnswer && !productComparison && (
        <div className="x-board-empty-state">
          <div className="x-board-empty-orbit" aria-hidden="true">
            <span>Budget</span><span>Use</span><span>Stock</span><strong>X</strong>
          </div>
          <div>
            <span className="x-board-eyebrow">Start with ordinary language</span>
            <h3>HexBot does the technical questioning for you.</h3>
            <p>
              Give the budget, main use and any preference that truly matters. Socket,
              platform, cooling and power matching stay inside the engine.
            </p>
            <div className="x-board-prompt-grid">
              <button type="button" onClick={() => onPrompt("Build me a balanced PC around Rs. 300,000")}>Build a complete PC</button>
              <button type="button" onClick={() => onPrompt("Find me a laptop for programming under Rs. 250,000")}>Recommend a laptop</button>
              <button type="button" onClick={() => onPrompt("Help me find the right technology product")}>Find a product</button>
              <button type="button" onClick={() => onPrompt("I want to ask a technical hardware question")}>Ask a tech question</button>
            </div>
          </div>
        </div>
      )}

      {productComparison && !activeBuild && recommendations.length === 0 && (
        <section className={`x-board-product-comparison is-${productComparison.status}`}>
          {productComparison.status === "ready" ? (
            <>
              <div className="x-board-technical-heading">
                <div>
                  <span>LIVE PRODUCT COMPARISON</span>
                  <h3>{productComparison.title}</h3>
                </div>
                <strong>{productComparison.category} · {productComparison.use_case?.replaceAll("_", " ")}</strong>
              </div>
              <div className="x-board-comparison-products">
                {productComparison.products?.map((product) => (
                  <ComparisonProductCard
                    product={product}
                    verdict={productComparison.verdict}
                    navigate={navigate}
                    onAction={onPrompt}
                    key={product.product_id}
                  />
                ))}
              </div>
              <div className="x-board-comparison-table-wrap">
                <table className="x-board-comparison-table">
                  <thead>
                    <tr>
                      <th>Verified field</th>
                      {productComparison.products?.map((product) => <th key={product.product_id}>{product.name}</th>)}
                    </tr>
                  </thead>
                  <tbody>
                    {productComparison.rows?.map((row) => (
                      <tr key={row.code}>
                        <th>{row.label}</th>
                        <td className={Number(row.winner_product_id) === Number(productComparison.products?.[0]?.product_id) ? "is-advantage" : ""}>{row.left_value}</td>
                        <td className={Number(row.winner_product_id) === Number(productComparison.products?.[1]?.product_id) ? "is-advantage" : ""}>{row.right_value}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="x-board-comparison-verdict">
                <span>HEXBOT'S EVIDENCE-BASED VIEW</span>
                <h4>{productComparison.verdict?.headline}</h4>
                <p>{productComparison.verdict?.guidance}</p>
                <div className="x-board-answer-actions">
                  <button type="button" onClick={() => onPrompt("Show similar products", "compare:find-related")}>Show similar products</button>
                  <button type="button" onClick={() => onPrompt("Compare another pair", "question:compare")}>Compare another pair</button>
                </div>
              </div>
              <p className="x-board-technical-caution"><strong>Important:</strong> {productComparison.limitations}</p>
            </>
          ) : (
            <>
              <div className="x-board-technical-heading">
                <div><span>PRODUCT COMPARISON</span><h3>One more detail is needed</h3></div>
                <strong>Live catalogue</strong>
              </div>
              <p className="x-board-technical-summary">{productComparison.message}</p>
              {productComparison.candidates?.length > 0 && (
                <div className="x-board-comparison-candidates">
                  {productComparison.candidates.map((candidate) => (
                    <article key={candidate.product_id}>
                      <strong>{candidate.name}</strong>
                      <span>{candidate.brand} · {candidate.model}</span>
                      <small>{formatMoney(candidate.price_lkr)} · {candidate.available_quantity} available</small>
                    </article>
                  ))}
                </div>
              )}
            </>
          )}
        </section>
      )}

      {technicalAnswer && !activeBuild && recommendations.length === 0 && (
        <section className={`x-board-technical-answer ${technicalAnswer.supported ? "is-supported" : "is-limited"}`}>
          <div className="x-board-technical-heading">
            <div>
              <span>HEXBOT TECHNICAL ANSWER</span>
              <h3>{technicalAnswer.title}</h3>
            </div>
            <strong>{technicalAnswer.supported ? "Controlled knowledge" : "Safe limitation"}</strong>
          </div>
          <p className="x-board-technical-summary">{technicalAnswer.summary}</p>
          {technicalAnswer.points?.length > 0 && (
            <div className="x-board-technical-points">
              {technicalAnswer.points.map((point) => <p key={point}>{point}</p>)}
            </div>
          )}
          <div className="x-board-technical-guidance">
            <span>HEXBOT'S GUIDANCE</span>
            <p>{technicalAnswer.recommendation}</p>
          </div>
          {technicalAnswer.caution && (
            <p className="x-board-technical-caution"><strong>Important:</strong> {technicalAnswer.caution}</p>
          )}
          {technicalAnswer.actions && (
            <div className="x-board-answer-actions">
              {technicalAnswer.actions.related_search && (
                <button
                  type="button"
                  onClick={() => onPrompt(
                    technicalAnswer.actions.related_search.label,
                    "tech:find-related"
                  )}
                >
                  {technicalAnswer.actions.related_search.label}
                </button>
              )}
              {technicalAnswer.actions.pc_seed && (
                <button
                  type="button"
                  className="is-secondary"
                  onClick={() => onPrompt(
                    technicalAnswer.actions.pc_seed.label,
                    "tech:start-pc"
                  )}
                >
                  {technicalAnswer.actions.pc_seed.label}
                </button>
              )}
            </div>
          )}
        </section>
      )}

      {recommendations.length > 0 && !activeBuild && (
        <div className="x-board-laptop-results">
          <div className="x-board-section-heading">
            <div><span>LAPTOP RECOMMENDATIONS</span><h3>Best current matches</h3></div>
            <strong>{recommendations.length} of {Math.max(eligibleCount, recommendations.length)} eligible</strong>
          </div>
          <div>
            {recommendations.map((item) => (
              <RecommendationCard key={item.product_id} item={item} navigate={navigate} />
            ))}
          </div>
        </div>
      )}

      {recommendations.length > 0 && !activeBuild && requestedLaptopAccessories.length > 0 && (
        <section className="x-board-laptop-accessories" aria-label="Accessories recommended for this laptop search">
          <div className="x-board-section-heading">
            <div>
              <span>ADDED FOR YOUR LAPTOP</span>
              <h3>Purpose-matched accessories</h3>
            </div>
            <strong>
              {laptopAccessoryEntries.length} of {requestedLaptopAccessories.length} matched ·{" "}
              {formatMoney(laptopAccessories?.peripheral_total_price_lkr)}
            </strong>
          </div>
          {laptopAccessoryEntries.length > 0 && (
            <div className="x-board-laptop-accessory-grid">
              {laptopAccessoryEntries.map(([category, item]) => (
                <LaptopAccessoryCard
                  category={category}
                  item={item}
                  navigate={navigate}
                  key={category}
                />
              ))}
            </div>
          )}
          {laptopAccessoryNotices.length > 0 && (
            <ul className="x-board-accessory-notices">
              {laptopAccessoryNotices.map((notice) => <li key={notice}>{notice}</li>)}
            </ul>
          )}
        </section>
      )}

      {activeBuild && (
        <div className="x-board-build-results">
          <div className="x-board-build-toolbar">
            <div>
              <span>COMPLETE PC RECOMMENDATIONS</span>
              <h3>Select a build to inspect every component</h3>
            </div>
            <div className="x-board-build-tabs" role="tablist" aria-label="Recommended PC builds">
              {pcBuilds.map((build) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={activeBuild.rank === build.rank}
                  className={activeBuild.rank === build.rank ? "is-active" : ""}
                  onClick={() => {
                    setActivePcRank(build.rank);
                    setCartState({ loading: false, error: "", receipt: null });
                  }}
                  key={build.rank}
                >
                  Build {build.rank}<strong>{formatMoney(build.total_price_lkr)}</strong>
                </button>
              ))}
            </div>
          </div>

          <div className="x-board-budget-strip">
            <div><span>Selected total</span><strong>{formatMoney(selectedTotal)}</strong></div>
            <div>
              <span>Target</span>
              <strong>{formatMoney(pcBudgetAnalysis?.target_budget_lkr)}</strong>
              {maximumBudget > targetBudget && <small>Ceiling {formatMoney(maximumBudget)}</small>}
            </div>
            <div>
              <span>Budget position</span>
              <strong className={selectedBudgetPosition === "Above ceiling" ? "is-over-budget" : ""}>{selectedBudgetPosition}</strong>
            </div>
            <div><span>Compatibility</span><strong className="is-compatible">{activeBuild.compatibility?.status}</strong></div>
          </div>

          {peripheralEntries.length > 0 && (
            <section className="x-board-included-peripherals" aria-label="Peripherals included in this recommendation">
              <div className="x-board-included-heading">
                <div>
                  <span>ADDED TO THIS RECOMMENDATION</span>
                  <h3>{peripheralHeading}</h3>
                </div>
                <strong>{peripheralEntries.length} {peripheralEntries.length === 1 ? "product" : "products"} · {formatMoney(selectedPeripheralTotal)}</strong>
              </div>
              <div className="x-board-included-list">
                {peripheralEntries.map(([field, component]) => {
                  const key = `${activeBuild.rank}:peripheral:${field}`;
                  const offer = selectedOffer(component, key);
                  return (
                    <article key={field}>
                      <span>{peripheralLabels[field] ?? field}</span>
                      <strong>{component.name}</strong>
                      <small>{offer?.shop_name} · {formatMoney(offer?.price_lkr)}</small>
                    </article>
                  );
                })}
              </div>
            </section>
          )}

          <div className={`x-board-purchase-bar ${cartState.error ? "has-error" : ""} ${cartState.receipt ? "has-success" : ""}`}>
            <div>
              <span>{cartState.receipt ? "SETUP READY IN CART" : "BUY THE SELECTED SETUP"}</span>
              <strong>
                {cartState.receipt
                  ? cartState.receipt.name
                  : "Every selected seller offer is checked together before it is added."}
              </strong>
              {cartState.receipt && (
                <small>
                  {cartState.receipt.added_item_count} products from {cartState.receipt.shop_count} {cartState.receipt.shop_count === 1 ? "shop" : "shops"} · permanent setup ID {cartState.receipt.public_id}
                </small>
              )}
              {cartState.error && <p role="alert">{cartState.error}</p>}
            </div>
            <div>
              {cartState.receipt ? (
                <>
                  <button type="button" className="x-board-secondary-action" onClick={() => navigate("/cart")}>View cart</button>
                  <button type="button" className="x-board-buy-action" onClick={() => navigate("/checkout")}>Checkout</button>
                </>
              ) : (
                <button
                  type="button"
                  className="x-board-buy-action"
                  onClick={addSetupToCart}
                  disabled={cartState.loading || selectedSetupItems.length === 0 || (user && user.role !== "customer")}
                >
                  {cartState.loading ? "Checking live offers…" : !user ? "Sign in to buy setup" : `Add ${selectedSetupItems.length} products to cart`}
                </button>
              )}
            </div>
          </div>

          <div className="x-board-build-layout">
            <div className="x-board-component-panel">
              <div className="x-board-section-heading">
                <div><span>SELECTED PRODUCTS</span><h3>Complete component list</h3></div>
                <strong>{Object.keys(activeBuild.components ?? {}).length} products</strong>
              </div>
              <div className="x-board-component-grid">
                {pcEntries.map(([field, component]) => {
                  const key = `${activeBuild.rank}:pc:${field}`;
                  const offer = selectedOffer(component, key);
                  return <article key={field}>
                    <div className="x-board-component-code">{pcComponentLabels[field]?.slice(0, 3).toUpperCase()}</div>
                    <div>
                      <span>{pcComponentLabels[field] ?? field.replaceAll("_", " ")}</span>
                      <h4>{component.name}</h4>
                      {component.offers?.length > 1 ? (
                        <label className="x-board-offer-picker">
                          <span>Seller offer</span>
                          <select value={offer?.listing_id ?? ""} onChange={(event) => chooseOffer(key, event.target.value)}>
                            {component.offers.map((candidate) => (
                              <option value={candidate.listing_id} key={candidate.listing_id}>
                                {candidate.shop_name} · {formatMoney(candidate.price_lkr)} · {candidate.available_quantity} in stock
                              </option>
                            ))}
                          </select>
                        </label>
                      ) : <p>{offer?.shop_name} · {offer?.available_quantity} in stock</p>}
                    </div>
                    <div>
                      <strong>{formatMoney(offer?.price_lkr)}</strong>
                      <button type="button" onClick={() => navigate(`/products/${component.product_id}`)}>View product</button>
                    </div>
                  </article>;
                })}
                {!activeBuild.components?.graphics_card && (
                  <article className="is-integrated-graphics">
                    <div className="x-board-component-code">GPU</div>
                    <div>
                      <span>Graphics</span>
                      <h4>Processor-integrated graphics</h4>
                      <p>No separate graphics card was required for the confirmed workload.</p>
                    </div>
                    <button type="button" onClick={() => onPrompt("Change this build to require a dedicated graphics card")}>Request dedicated GPU</button>
                  </article>
                )}
              </div>
              {peripheralEntries.length > 0 && (
                <section className="x-board-peripheral-section">
                  <div className="x-board-section-heading">
                    <div>
                      <span>PURPOSE-MATCHED PERIPHERALS</span>
                      <h3>{peripheralHeading}</h3>
                    </div>
                    <strong>{formatMoney(selectedPeripheralTotal)}</strong>
                  </div>
                  {activeBuild.setup_data_mode === "demonstration" && (
                    <p className="x-board-demo-notice">Local demonstration catalogue · simulated prices · not verified market claims</p>
                  )}
                  <div className="x-board-component-grid">
                    {peripheralEntries.map(([field, component]) => {
                      const key = `${activeBuild.rank}:peripheral:${field}`;
                      const offer = selectedOffer(component, key);
                      return <article key={field}>
                        <div className="x-board-component-code">{peripheralLabels[field]?.slice(0, 3).toUpperCase()}</div>
                        <div>
                          <span>{peripheralLabels[field] ?? field}</span>
                          <h4>{component.name}</h4>
                          <p>{component.profile?.replaceAll("_", " ")} fit · {Number(component.fit_score ?? 0).toFixed(1)}/100</p>
                          {component.offers?.length > 1 && (
                            <label className="x-board-offer-picker">
                              <span>Seller offer</span>
                              <select value={offer?.listing_id ?? ""} onChange={(event) => chooseOffer(key, event.target.value)}>
                                {component.offers.map((candidate) => (
                                  <option value={candidate.listing_id} key={candidate.listing_id}>
                                    {candidate.shop_name} · {formatMoney(candidate.price_lkr)} · {candidate.available_quantity} in stock
                                  </option>
                                ))}
                              </select>
                            </label>
                          )}
                          {component.offers?.length <= 1 && <p>{offer?.shop_name} · {offer?.available_quantity} in stock</p>}
                        </div>
                        <div>
                          <strong>{formatMoney(offer?.price_lkr)}</strong>
                          <button type="button" onClick={() => navigate(`/products/${component.product_id}`)}>View product</button>
                        </div>
                      </article>;
                    })}
                  </div>
                </section>
              )}
            </div>

            <aside className="x-board-analysis-panel">
              <section>
                <span>WHY HEXBOT CHOSE IT</span>
                <h3>Decision evidence</h3>
                <ul>{decisionReasons.map((reason) => <li key={reason}>{reason}</li>)}</ul>
              </section>
              <section>
                <span>BUILD SCORES</span>
                <h3>Measured balance</h3>
                <div className="x-board-score-grid">
                  {Object.entries(pcScoreLabels).map(([code, label]) => (
                    <div key={code}>
                      <span>{label}</span>
                      <strong>{Number(activeBuild.scores?.[code] ?? 0).toFixed(1)}</strong>
                      <i><b style={{ width: `${Math.min(100, Number(activeBuild.scores?.[code] ?? 0))}%` }} /></i>
                    </div>
                  ))}
                </div>
              </section>
              <section>
                <span>TRADE-OFFS</span>
                <h3>What to know</h3>
                {activeBuild.trade_offs?.length ? (
                  <ul>{activeBuild.trade_offs.map((item) => <li key={item}>{item}</li>)}</ul>
                ) : <p>No material trade-off was identified.</p>}
                <button type="button" className="x-board-refine-button" onClick={() => onPrompt("I want to change this PC build")}>Refine with HexBot</button>
              </section>
            </aside>
          </div>
        </div>
      )}
    </section>
  );
}

export default function HexBotWidget({ navigate, dashboard = false, onExitDashboard }) {
  const { user, token } = useAuth();
  const storedWorkspace = useRef(getStoredWorkspace()).current;
  const [open, setOpen] = useState(false);
  const [ready, setReady] = useState(false);
  const [loading, setLoading] = useState(false);
  const [sessionId, setSessionId] = useState("");
  const [messages, setMessages] = useState([]);
  const [options, setOptions] = useState([]);
  const [recommendations, setRecommendations] = useState(
    Array.isArray(storedWorkspace.recommendations) ? storedWorkspace.recommendations : []
  );
  const [laptopAccessories, setLaptopAccessories] = useState(
    storedWorkspace.laptopAccessories && typeof storedWorkspace.laptopAccessories === "object"
      ? storedWorkspace.laptopAccessories
      : null
  );
  const [technicalAnswer, setTechnicalAnswer] = useState(
    storedWorkspace.technicalAnswer && typeof storedWorkspace.technicalAnswer === "object"
      ? storedWorkspace.technicalAnswer
      : null
  );
  const [productComparison, setProductComparison] = useState(
    storedWorkspace.productComparison && typeof storedWorkspace.productComparison === "object"
      ? storedWorkspace.productComparison
      : null
  );
  const [pcBuilds, setPcBuilds] = useState(
    Array.isArray(storedWorkspace.pcBuilds) ? storedWorkspace.pcBuilds : []
  );
  const [pcBudgetAnalysis, setPcBudgetAnalysis] = useState(storedWorkspace.pcBudgetAnalysis ?? null);
  const [pcRecommendationId, setPcRecommendationId] = useState(storedWorkspace.pcRecommendationId ?? null);
  const [pcBuilderRequest, setPcBuilderRequest] = useState(storedWorkspace.pcBuilderRequest ?? null);
  const [activePcRank, setActivePcRank] = useState(1);
  const [gamingAlternatives, setGamingAlternatives] = useState(
    Array.isArray(storedWorkspace.gamingAlternatives) ? storedWorkspace.gamingAlternatives : []
  );
  const [showGamingAlternatives, setShowGamingAlternatives] = useState(false);
  const [eligibleCount, setEligibleCount] = useState(Number(storedWorkspace.eligibleCount) || 0);
  const [relaxations, setRelaxations] = useState(
    Array.isArray(storedWorkspace.relaxations) ? storedWorkspace.relaxations : []
  );
  const [mascotState, setMascotState] = useState("wave");
  const [input, setInput] = useState("");
  const [error, setError] = useState("");
  const [nudge, setNudge] = useState("");
  const [hasNewMessages, setHasNewMessages] = useState(false);
  const chatLogRef = useRef(null);
  const isNearBottomRef = useRef(true);
  const forceScrollRef = useRef(false);
  const sleepTimerRef = useRef(null);
  const stateTimerRef = useRef(null);
  const hasNudgedRef = useRef(false);
  const mascotStateRef = useRef("wave");
  const dashboardOpenedRef = useRef(false);
  const workspaceRef = useRef(null);

  useEffect(() => {
    mascotStateRef.current = mascotState;
  }, [mascotState]);

  const showTemporaryState = useCallback((state, duration = 2200) => {
    window.clearTimeout(stateTimerRef.current);
    setMascotState(state);
    stateTimerRef.current = window.setTimeout(() => {
      setMascotState("idle");
    }, duration);
  }, []);

  useEffect(() => {
    showTemporaryState("wave", 2600);
    return () => window.clearTimeout(stateTimerRef.current);
  }, [showTemporaryState]);

  useEffect(() => {
    const resetSleepTimer = () => {
      window.clearTimeout(sleepTimerRef.current);
      if (mascotStateRef.current === "sleep") {
        showTemporaryState("wave");
      }
      sleepTimerRef.current = window.setTimeout(() => {
        if (!open) setMascotState("sleep");
      }, FIVE_MINUTES);
    };
    const events = ["scroll", "pointerdown", "keydown", "touchstart"];
    events.forEach((event) =>
      window.addEventListener(event, resetSleepTimer, { passive: true })
    );
    resetSleepTimer();
    return () => {
      window.clearTimeout(sleepTimerRef.current);
      events.forEach((event) =>
        window.removeEventListener(event, resetSleepTimer)
      );
    };
  }, [open, showTemporaryState]);

  useEffect(() => {
    const nudgeTimer = window.setTimeout(() => {
      if (!open && !hasNudgedRef.current) {
        hasNudgedRef.current = true;
        setNudge("Still deciding? I can help you choose.");
        showTemporaryState("wave", 3500);
      }
    }, FIVE_MINUTES);
    return () => window.clearTimeout(nudgeTimer);
  }, [open, showTemporaryState]);

  const scrollChatToBottom = useCallback((behavior = "smooth") => {
    const chatLog = chatLogRef.current;
    if (!chatLog) return;
    chatLog.scrollTo({ top: chatLog.scrollHeight, behavior });
    isNearBottomRef.current = true;
    setHasNewMessages(false);
  }, []);

  useEffect(() => {
    if (!open) return;
    if (forceScrollRef.current || isNearBottomRef.current) {
      const forced = forceScrollRef.current;
      const frame = window.requestAnimationFrame(() =>
        scrollChatToBottom(forced ? "auto" : "smooth")
      );
      forceScrollRef.current = false;
      return () => window.cancelAnimationFrame(frame);
    }
    setHasNewMessages(true);
  }, [messages, loading, open, recommendations, laptopAccessories, technicalAnswer, productComparison, pcBuilds, scrollChatToBottom]);

  const handleChatScroll = (event) => {
    const element = event.currentTarget;
    const nearBottom =
      element.scrollHeight - element.scrollTop - element.clientHeight < 72;
    isNearBottomRef.current = nearBottom;
    if (nearBottom) setHasNewMessages(false);
  };

  const applyResponse = useCallback((data, preserveWorkspace = false) => {
    setSessionId(data.session?.public_id ?? "");
    setMessages(Array.isArray(data.messages) ? data.messages : []);
    setOptions(Array.isArray(data.options) ? data.options : []);
    const workspace = {
      recommendations: Array.isArray(data.recommendations) ? data.recommendations : [],
      laptopAccessories: data.laptop_accessories && typeof data.laptop_accessories === "object"
        ? data.laptop_accessories
        : null,
      technicalAnswer: data.technical_answer && typeof data.technical_answer === "object"
        ? data.technical_answer
        : null,
      productComparison: data.product_comparison && typeof data.product_comparison === "object"
        ? data.product_comparison
        : null,
      pcBuilds: Array.isArray(data.pc_build_recommendations) ? data.pc_build_recommendations : [],
      pcBudgetAnalysis: data.pc_budget_analysis ?? null,
      pcRecommendationId: data.pc_recommendation_id ?? null,
      pcBuilderRequest: data.pc_builder_request ?? null,
      gamingAlternatives: Array.isArray(data.gaming_capable_alternatives) ? data.gaming_capable_alternatives : [],
      eligibleCount: Number(data.eligible_candidate_count) || 0,
      relaxations: Array.isArray(data.relaxation_suggestions) ? data.relaxation_suggestions : [],
    };
    const hasWorkspacePayload = Object.prototype.hasOwnProperty.call(data, "recommendations")
      || Object.prototype.hasOwnProperty.call(data, "pc_build_recommendations")
      || Object.prototype.hasOwnProperty.call(data, "technical_answer")
      || Object.prototype.hasOwnProperty.call(data, "product_comparison");
    const hasLaptopAccessoriesPayload = Object.prototype.hasOwnProperty.call(
      data,
      "laptop_accessories"
    );
    const shouldReplaceWorkspace = !preserveWorkspace
      && (hasWorkspacePayload || data.clear_workspace === true);
    if (shouldReplaceWorkspace) {
      setRecommendations(workspace.recommendations);
      setLaptopAccessories(workspace.laptopAccessories);
      setTechnicalAnswer(workspace.technicalAnswer);
      setProductComparison(workspace.productComparison);
      setPcBuilds(workspace.pcBuilds);
      setPcBudgetAnalysis(workspace.pcBudgetAnalysis);
      setPcRecommendationId(workspace.pcRecommendationId);
      setPcBuilderRequest(workspace.pcBuilderRequest);
      setGamingAlternatives(workspace.gamingAlternatives);
      setEligibleCount(workspace.eligibleCount);
      setRelaxations(workspace.relaxations);
      window.sessionStorage.setItem(WORKSPACE_STORAGE, JSON.stringify(workspace));
      if (workspace.pcBuilds.length) {
        setActivePcRank(workspace.pcBuilds[0].rank ?? 1);
      }
    } else if (hasLaptopAccessoriesPayload) {
      setLaptopAccessories(workspace.laptopAccessories);
      const stored = getStoredWorkspace();
      window.sessionStorage.setItem(
        WORKSPACE_STORAGE,
        JSON.stringify({ ...stored, laptopAccessories: workspace.laptopAccessories })
      );
    }
    setShowGamingAlternatives(false);
    setMascotState(data.mascot_state || "idle");
  }, []);

  const start = useCallback(async (forceFresh = false) => {
    setLoading(true);
    setError("");
    try {
      if (forceFresh) {
        window.sessionStorage.setItem(
          SESSION_KEY_STORAGE,
          createSessionKey()
        );
        setInput("");
        setHasNewMessages(false);
        isNearBottomRef.current = true;
        forceScrollRef.current = true;
        window.sessionStorage.removeItem(WORKSPACE_STORAGE);
        setRecommendations([]);
        setLaptopAccessories(null);
        setTechnicalAnswer(null);
        setProductComparison(null);
        setPcBuilds([]);
        setPcBudgetAnalysis(null);
        setPcRecommendationId(null);
        setPcBuilderRequest(null);
        setGamingAlternatives([]);
        setEligibleCount(0);
        setRelaxations([]);
      }
      const response = await apiRequest("/hexbot/sessions", {
        method: "POST",
        body: { session_key: getSessionKey() },
      });
      applyResponse(
        response.data,
        !forceFresh && ["pc_results", "laptop_results", "technical_question", "product_comparison_clarify"].includes(
          response.data.session?.state_code
        )
      );
      setReady(true);
    } catch (requestError) {
      setError(
        requestError.message ||
          "HexBot could not connect. Please check that HEXBAY is running."
      );
    } finally {
      setLoading(false);
    }
  }, [applyResponse]);

  const openWidget = useCallback(() => {
    forceScrollRef.current = true;
    setOpen(true);
    setNudge("");
    showTemporaryState("wave");
    if (!ready && !loading) start();
  }, [loading, ready, showTemporaryState, start]);

  useEffect(() => {
    if (dashboard && !dashboardOpenedRef.current) {
      dashboardOpenedRef.current = true;
      openWidget();
    } else if (!dashboard) {
      dashboardOpenedRef.current = false;
    }
  }, [dashboard, openWidget]);

  useEffect(() => {
    if (!dashboard || (!pcBuilds.length && !recommendations.length)) return;
    const frame = window.requestAnimationFrame(() => {
      workspaceRef.current?.scrollTo({ top: 0, behavior: "smooth" });
    });
    return () => window.cancelAnimationFrame(frame);
  }, [dashboard, pcBuilds, recommendations, laptopAccessories, technicalAnswer, productComparison]);

  useEffect(() => {
    const handleOpenRequest = () => openWidget();
    window.addEventListener("hexbay:open-hexbot", handleOpenRequest);
    return () => window.removeEventListener("hexbay:open-hexbot", handleOpenRequest);
  }, [openWidget]);

  const send = async (message, action = null) => {
    if (!sessionId || loading) return;
    const cleanMessage = message.trim();
    if (!cleanMessage && !action) return;
    setLoading(true);
    forceScrollRef.current = true;
    setError("");
    setMascotState("thinking");
    try {
      const response = await apiRequest(
        `/hexbot/sessions/${encodeURIComponent(sessionId)}/messages`,
        {
          method: "POST",
          body: {
            session_key: getSessionKey(),
            message: cleanMessage,
            ...(action ? { action } : {}),
          },
        }
      );
      applyResponse(
        response.data,
        ["open:x-board", "open:pc-builder"].includes(action)
      );
      if (response.data.navigation?.type === "product_search") {
        const query = new URLSearchParams({
          search: response.data.navigation.query,
        });
        setOpen(false);
        navigate(`/products?${query.toString()}`);
      } else if (["x_board", "pc_builder"].includes(response.data.navigation?.type)) {
        navigate("/x-board");
      } else if (response.data.navigation?.type === "product_detail") {
        setOpen(false);
        navigate(`/products/${Number(response.data.navigation.product_id)}`);
      }
    } catch (requestError) {
      if ([404, 429].includes(requestError.status)) {
        setReady(false);
        setSessionId("");
        await start(true);
        setError("I started a fresh conversation. Please send that again.");
      } else {
        setError(requestError.message || "I could not send that message.");
        setMascotState("idle");
      }
    } finally {
      setLoading(false);
    }
  };

  const submit = (event) => {
    event.preventDefault();
    const message = input;
    setInput("");
    send(message);
  };

  const visibleOptions = dashboard
    ? options.filter((option) => !["open:x-board", "open:pc-builder"].includes(option.id))
    : options;

  return (
    <aside className={`hexbot-widget ${open ? "is-open" : ""} ${dashboard ? "is-dashboard" : ""}`}>
      {!dashboard && !open && nudge && (
        <button
          type="button"
          className="hexbot-nudge"
          onClick={openWidget}
        >
          <strong>HexBot</strong>
          <span>{nudge}</span>
        </button>
      )}

      {(open || dashboard) && (
        <section
          className="hexbot-panel"
          role="dialog"
          aria-modal={dashboard ? "true" : "false"}
          aria-label={dashboard ? "X Board by HexBot" : "Chat with HexBot"}
        >
          <header className="hexbot-panel-header">
            <div className="hexbot-header-identity">
              <Mascot state={mascotState} className="hexbot-header-mascot" />
              <div>
                <strong>{dashboard ? "X Board" : "HexBot"}</strong>
                <span>
                  <i aria-hidden="true" /> {dashboard ? "HexBot's intelligent workspace" : "HEXBAY shopping assistant"}
                </span>
              </div>
            </div>
            <div className="hexbot-header-actions">
              {!dashboard && (
                <button
                  type="button"
                  className="hexbot-expand"
                  aria-label="Expand HexBot into X Board"
                  title="Open X Board"
                  onClick={() => navigate("/x-board")}
                >
                  ↗
                </button>
              )}
              <button
                type="button"
                className="hexbot-reset"
                aria-label="Start a new HexBot conversation"
                title="New chat"
                onClick={() => start(true)}
                disabled={loading}
              >
                ↻
              </button>
              <button
                type="button"
                className="hexbot-close"
                aria-label={dashboard ? "Minimize X Board" : "Close HexBot"}
                title={dashboard ? "Return to compact HexBot" : "Close HexBot"}
                onClick={() => {
                  if (dashboard) {
                    setOpen(true);
                    onExitDashboard?.();
                  } else {
                    setOpen(false);
                  }
                }}
              >
                ×
              </button>
            </div>
          </header>

          <div className={`hexbot-panel-body ${dashboard ? "is-dashboard" : ""}`}>
            <div className="hexbot-conversation-shell">
              {dashboard && (
                <div className="x-board-conversation-heading">
                  <span>CONVERSATION</span>
                  <strong>Tell HexBot what should change.</strong>
                </div>
              )}
              <div
                ref={chatLogRef}
                className="hexbot-chat-log"
                aria-live="polite"
                onScroll={handleChatScroll}
              >
                {messages.map((message) => (
                  <div
                    key={message.id}
                    className={`hexbot-message hexbot-message-${message.sender}`}
                  >
                    {message.message_text}
                  </div>
                ))}

                {!dashboard && recommendations.length > 0 && (
                  <div className="hexbot-results">
                    <p className="hexbot-result-count">
                      Showing {recommendations.length} of{" "}
                      {Math.max(eligibleCount, recommendations.length)} matching
                      {Math.max(eligibleCount, recommendations.length) === 1 ? " laptop" : " laptops"}
                    </p>
                    {recommendations.map((item) => (
                      <RecommendationCard key={item.product_id} item={item} navigate={navigate} />
                    ))}
                  </div>
                )}

                {!dashboard && pcBuilds.length > 0 && (
                  <div className="hexbot-pc-results">
                    <p className="hexbot-result-count">
                      {pcBuilds.length} complete compatible build{pcBuilds.length === 1 ? "" : "s"}
                    </p>
                    {pcBudgetAnalysis?.shortfall_lkr > 0 && (
                      <p className="hexbot-pc-shortfall">
                        Current catalogue shortfall: {formatMoney(pcBudgetAnalysis.shortfall_lkr)}
                      </p>
                    )}
                    {pcBuilds.map((build) => (
                      <PcBuildCard build={build} onOpen={() => navigate("/x-board")} key={build.rank} />
                    ))}
                  </div>
                )}

                {!dashboard && gamingAlternatives.length > 0 && (
                  <section className="hexbot-gaming-alternatives">
                    <button
                      type="button"
                      className="hexbot-alternatives-toggle"
                      onClick={() => setShowGamingAlternatives((current) => !current)}
                      aria-expanded={showGamingAlternatives}
                    >
                      {showGamingAlternatives ? "Hide" : "Show"} {gamingAlternatives.length} gaming-capable alternative{gamingAlternatives.length === 1 ? "" : "s"}
                    </button>
                    {showGamingAlternatives && (
                      <div className="hexbot-results">
                        <p className="hexbot-alternative-note">
                          These can run games, but HEXBAY does not classify them as dedicated gaming laptops.
                        </p>
                        {gamingAlternatives.map((item) => (
                          <RecommendationCard key={`gaming-alternative-${item.product_id}`} item={item} navigate={navigate} alternative />
                        ))}
                      </div>
                    )}
                  </section>
                )}

                {relaxations.length > 0 && (
                  <div className="hexbot-relaxations">
                    <strong>You could also try:</strong>
                    <ul>
                      {relaxations.slice(0, 3).map((suggestion, index) => (
                        <li key={`${index}-${JSON.stringify(suggestion)}`}>
                          {typeof suggestion === "string" ? suggestion : suggestion.message || suggestion.label || "Relax one requirement"}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {loading && (
                  <div className="hexbot-typing" aria-label="HexBot is thinking"><span /><span /><span /></div>
                )}
                {error && <div className="hexbot-error" role="alert">{error}</div>}
              </div>

              {hasNewMessages && (
                <button type="button" className="hexbot-new-messages" onClick={() => scrollChatToBottom()}>
                  New messages ↓
                </button>
              )}

              {visibleOptions.length > 0 && !loading && (
                <div className="hexbot-options" aria-label="Suggested replies">
                  {visibleOptions.map((option) => (
                    <button key={option.id} type="button" onClick={() => send(option.label, option.id)}>{option.label}</button>
                  ))}
                </div>
              )}

              <form className="hexbot-composer" onSubmit={submit}>
                <input
                  value={input}
                  onChange={(event) => setInput(event.target.value)}
                  placeholder="Tell HexBot what you need…"
                  maxLength={500}
                  disabled={!ready || loading}
                  aria-label="Message HexBot"
                />
                <button type="submit" disabled={!ready || loading || !input.trim()} aria-label="Send message">Send</button>
              </form>
              <small className="hexbot-safety-note">
                Controlled assistant · Recommendations use live HEXBAY stock
              </small>
            </div>

            {dashboard && (
              <XBoardWorkspace
                pcBuilds={pcBuilds}
                pcBudgetAnalysis={pcBudgetAnalysis}
                recommendations={recommendations}
                laptopAccessories={laptopAccessories}
                technicalAnswer={technicalAnswer}
                productComparison={productComparison}
                eligibleCount={eligibleCount}
                activePcRank={activePcRank}
                setActivePcRank={setActivePcRank}
                navigate={navigate}
                onPrompt={(message, action = null) => send(message, action)}
                workspaceRef={workspaceRef}
                user={user}
                token={token}
                pcRecommendationId={pcRecommendationId}
                pcBuilderRequest={pcBuilderRequest}
              />
            )}
          </div>
        </section>
      )}

      {!dashboard && <button
        className="hexbot-launcher"
        type="button"
        onClick={open ? () => setOpen(false) : openWidget}
        title={open ? "Close HexBot" : "Ask HexBot"}
        aria-label={open ? "Close HexBot" : "Open HexBot"}
        aria-expanded={open}
      >
        {open ? (
          <span className="hexbot-launcher-close">×</span>
        ) : (
          <Mascot state={mascotState} />
        )}
        {!open && <span className="hexbot-launcher-label">Ask HexBot</span>}
      </button>}
    </aside>
  );
}
