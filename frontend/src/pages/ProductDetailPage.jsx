import { useEffect, useMemo, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import StatusBadge from "../components/StatusBadge";
import { useAuth } from "../contexts/AuthContext";
import { useToast } from "../contexts/ToastContext";

const friendlyTechnicalLabel = (value) => {
  const label = String(value)
    .replaceAll("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
  return label
    .replace(/\bDirectx\b/g, "DirectX")
    .replace(/\bOpencl\b/g, "OpenCL")
    .replace(/\bCuda\b/g, "CUDA")
    .replace(/\bAv1\b/g, "AV1")
    .replace(/\bPcie\b/g, "PCIe")
    .replace(/\bUsb\b/g, "USB")
    .replace(/\bRgb\b/g, "RGB")
    .replace(/\bTwelve Vhpwr\b/g, "12VHPWR");
};

const friendlySpecificationValue = (specification) => {
  const value = specification.specification_value;
  if (specification.data_type === "boolean") {
    return String(value) === "1" || value === true ? "Yes" : "No";
  }
  if (["integer", "decimal"].includes(specification.data_type)) {
    const number = Number(value);
    return Number.isFinite(number)
      ? number.toLocaleString("en-LK", { maximumFractionDigits: 2 })
      : value;
  }
  if (specification.data_type === "multi_option") {
    try {
      const values = Array.isArray(value) ? value : JSON.parse(value);
      return values.map(friendlyTechnicalLabel).join(", ");
    } catch {
      return value;
    }
  }
  return specification.data_type === "option" ? friendlyTechnicalLabel(value) : value;
};

export default function ProductDetailPage({ productId, navigate }) {
  const { user, token } = useAuth();
  const { showToast } = useToast();
  const [product, setProduct] = useState(null);
  const [message, setMessage] = useState("");
  const [pendingAction, setPendingAction] = useState("");
  const [reportingOffer, setReportingOffer] = useState(null);
  const [report, setReport] = useState({
    reason_code: "suspicious_listing",
    description: "",
  });
  const [selectedImage, setSelectedImage] = useState("");

  useEffect(() => {
    apiRequest(`/products/${productId}`)
      .then((response) => {
        setProduct(response.data.product);
        setSelectedImage(
          response.data.product.offers.find((offer) => offer.image_filename)
            ?.image_filename ?? "",
        );
        if (user?.role === "customer") {
          Promise.all([
            apiRequest("/interactions", {
              method: "POST",
              token,
              body: { event_type: "view", product_id: productId },
            }),
            apiRequest("/interactions", {
              method: "POST",
              token,
              body: { event_type: "compare", product_id: productId },
            }),
          ]).catch(() => {});
        }
      })
      .catch((error) => setMessage(error.message));
  }, [productId, token, user?.role]);

  const bestOffer = useMemo(
    () => product?.offers.find((offer) => Number(offer.available_quantity) > 0),
    [product],
  );

  const buyerAction = async (path, body, successMessage, actionKey, destination) => {
    if (!user) {
      navigate("/login");
      return;
    }
    if (user.role !== "customer") {
      showToast("Sign in with a customer account to shop.", { type: "error" });
      return;
    }
    if (pendingAction) return;
    setPendingAction(actionKey);
    try {
      await apiRequest(path, { method: "POST", body, token });
      showToast(successMessage, {
        type: "success",
        actionLabel: destination ? "View" : undefined,
        onAction: destination ? () => navigate(destination) : undefined,
      });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setPendingAction("");
    }
  };

  const buyNow = async (offer) => {
    if (!user) {
      navigate("/login");
      return;
    }
    if (user.role !== "customer") {
      showToast("Sign in with a customer account to shop.", { type: "error" });
      return;
    }
    if (pendingAction) return;
    setPendingAction(`buy-${offer.listing_id}`);
    try {
      await apiRequest("/cart/items", {
        method: "POST",
        token,
        body: { listing_id: offer.listing_id, quantity: 1 },
      });
      showToast(`${product.name} was added. Review your card checkout details.`, {
        type: "success",
      });
      navigate("/checkout");
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setPendingAction("");
    }
  };

  if (!product) {
    return <div className="route-loading">{message || "Loading product…"}</div>;
  }

  return (
    <section className="content-section page-section product-detail-page">
      <button className="text-link" onClick={() => navigate("/products")}>
        ← Back to products
      </button>
      <div className="product-detail-hero">
        <div className="product-detail-visual">
          {selectedImage ? (
            <img
              src={mediaUrl("product-images", selectedImage)}
              alt={product.name}
            />
          ) : (
            <span>{product.brand_name.slice(0, 1)}</span>
          )}
        </div>
        <div>
          <span className="section-kicker">{product.category_name}</span>
          <h1>{product.name}</h1>
          <p className="product-model">
            {product.brand_name} · {product.model}
          </p>
          <div className="product-rating-line">
            <strong>★ {Number(product.rating_average).toFixed(1)}</strong>
            <span>{product.rating_count} verified reviews</span>
          </div>
          {bestOffer && (
            <div className="best-offer-summary">
              <small>Best available offer</small>
              <strong>LKR {Number(bestOffer.price).toLocaleString()}</strong>
              <span>
                {bestOffer.shop_name} · {bestOffer.available_quantity} available
              </span>
            </div>
          )}
          <p className="muted">
            Choose the approved seller offer that best matches your price,
            availability and warranty needs.
          </p>
        </div>
      </div>

      <section className="comparison-section">
        <div className="section-heading">
          <div>
            <span className="section-kicker">Same product, approved shops</span>
            <h2>Compare seller offers</h2>
          </div>
        </div>
        <div className="offer-comparison-list">
          {product.offers.map((offer) => (
            <article className="offer-comparison-card" key={offer.listing_id}>
              <div>
                <button
                  className="offer-shop-button"
                  onClick={() => navigate(`/shops/${offer.shop_id}`)}
                >
                  {offer.shop_name}
                </button>
                <span>
                  ★ {Number(offer.shop_rating).toFixed(1)} ·{" "}
                  {offer.shop_rating_count} reviews
                </span>
              </div>
              <StatusBadge status={offer.condition_type} />
              <div>
                <strong>LKR {Number(offer.price).toLocaleString()}</strong>
                <span>
                  {Number(offer.available_quantity) > 0
                    ? `${offer.available_quantity} in stock`
                    : "Out of stock"}
                </span>
              </div>
              <p>{offer.warranty_summary || "Ask the seller about warranty."}</p>
              <div className="offer-actions">
                <button
                  className="button button-primary"
                  disabled={Number(offer.available_quantity) < 1 || Boolean(pendingAction)}
                  onClick={() => buyNow(offer)}
                >
                  {pendingAction === `buy-${offer.listing_id}` ? "Opening checkout…" : "Buy now"}
                </button>
                <button
                  className="button button-ghost"
                  disabled={Number(offer.available_quantity) < 1 || Boolean(pendingAction)}
                  onClick={() =>
                    buyerAction(
                      "/cart/items",
                      { listing_id: offer.listing_id, quantity: 1 },
                      `${product.name} from ${offer.shop_name} was added to your cart.`,
                      `cart-${offer.listing_id}`,
                      "/cart",
                    )
                  }
                >
                  {pendingAction === `cart-${offer.listing_id}` ? "Adding…" : "Add to cart"}
                </button>
                <button
                  className="button button-ghost"
                  disabled={Boolean(pendingAction)}
                  onClick={() =>
                    buyerAction(
                      "/wishlist/items",
                      { listing_id: offer.listing_id },
                      `${offer.shop_name}'s offer was saved to your wishlist.`,
                      `wishlist-${offer.listing_id}`,
                      "/wishlist",
                    )
                  }
                >
                  {pendingAction === `wishlist-${offer.listing_id}` ? "Saving…" : "Add to wishlist"}
                </button>
                <button
                  className="text-link"
                  onClick={() =>
                    setReportingOffer(
                      reportingOffer === offer.listing_id ? null : offer.listing_id,
                    )
                  }
                >
                  Report concern
                </button>
              </div>
              {reportingOffer === offer.listing_id && (
                <form
                  className="inline-buyer-form offer-report-form"
                  onSubmit={async (event) => {
                    event.preventDefault();
                    if (!user) {
                      navigate("/login");
                      return;
                    }
                    if (pendingAction) return;
                    setPendingAction(`report-${offer.listing_id}`);
                    try {
                      await apiRequest("/counterfeit-reports", {
                        method: "POST",
                        token,
                        body: { listing_id: offer.listing_id, ...report },
                      });
                      setReportingOffer(null);
                      showToast("Your concern was sent privately for administrator review.", {
                        type: "success",
                      });
                    } catch (error) {
                      showToast(error.message, { type: "error", duration: 6000 });
                    } finally {
                      setPendingAction("");
                    }
                  }}
                >
                  <h4>Private product concern</h4>
                  <p>This requests a review; it does not automatically accuse the seller.</p>
                  <label>
                    Concern
                    <select
                      value={report.reason_code}
                      onChange={(event) =>
                        setReport({ ...report, reason_code: event.target.value })
                      }
                    >
                      <option value="suspicious_listing">Suspicious listing details</option>
                      <option value="packaging_concern">Packaging concern</option>
                      <option value="serial_mismatch">Serial or model mismatch</option>
                      <option value="misleading_brand">Misleading brand information</option>
                      <option value="other">Other concern</option>
                    </select>
                  </label>
                  <label className="full-width">
                    What did you notice?
                    <textarea
                      value={report.description}
                      required
                      onChange={(event) =>
                        setReport({ ...report, description: event.target.value })
                      }
                    />
                  </label>
                  <button className="button button-dark" disabled={Boolean(pendingAction)}>
                    {pendingAction === `report-${offer.listing_id}` ? "Submitting…" : "Submit privately"}
                  </button>
                </form>
              )}
            </article>
          ))}
        </div>
      </section>

      <div className="product-detail-columns">
        <section className="admin-panel">
          <h2>Structured specifications</h2>
          <dl className="specification-detail-list">
            {product.specifications.map((specification) => (
              <div key={specification.code}>
                <dt>{specification.display_name}</dt>
                <dd>
                  {friendlySpecificationValue(specification)}
                  {specification.unit ? ` ${specification.unit}` : ""}
                </dd>
              </div>
            ))}
          </dl>
        </section>
        <section className="admin-panel">
          <h2>Verified buyer reviews</h2>
          {product.reviews.length === 0 ? (
            <div className="compact-empty">No published reviews yet.</div>
          ) : (
            <div className="product-review-list">
              {product.reviews.map((review) => (
                <article key={review.id}>
                  <strong>{"★".repeat(Number(review.rating))}</strong>
                  <h3>{review.title || "Buyer review"}</h3>
                  <p>{review.review_text}</p>
                  <small>
                    {review.reviewer_name} · bought from {review.shop_name}
                  </small>
                </article>
              ))}
            </div>
          )}
        </section>
      </div>
    </section>
  );
}
