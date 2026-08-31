import { useEffect, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import StatusBadge from "../components/StatusBadge";
import Modal from "../components/Modal";
import { useAuth } from "../contexts/AuthContext";
import { useToast } from "../contexts/ToastContext";

const reportReasons = [
  ["packaging_concern", "Packaging concern"],
  ["serial_mismatch", "Serial or model mismatch"],
  ["misleading_brand", "Misleading brand information"],
  ["suspicious_listing", "Suspicious listing details"],
  ["other", "Other concern"],
];

const paymentLabels = {
  cash_on_delivery: "Cash on delivery (simulation)",
  card_simulation: "Card payment simulation",
  bank_transfer_simulation: "Bank transfer simulation",
};

export default function BuyerOrderDetailPage({ orderId, navigate }) {
  const { token } = useAuth();
  const { showToast } = useToast();
  const [order, setOrder] = useState(null);
  const [message, setMessage] = useState("");
  const [reviewingItem, setReviewingItem] = useState(null);
  const [review, setReview] = useState({ rating: 5, title: "", review_text: "" });
  const [complaintSubOrder, setComplaintSubOrder] = useState(null);
  const [confirmingReceipt, setConfirmingReceipt] = useState(null);
  const [complaint, setComplaint] = useState({ subject: "", description: "" });
  const [reportingItem, setReportingItem] = useState(null);
  const [report, setReport] = useState({
    reason_code: "packaging_concern",
    description: "",
  });
  const [submittingAction, setSubmittingAction] = useState("");

  const load = () =>
    apiRequest(`/orders/${orderId}`, { token })
      .then((response) => setOrder(response.data.order))
      .catch((error) => setMessage(error.message));

  useEffect(() => {
    load();
  }, [orderId, token]);

  if (!order) {
    return <div className="route-loading">{message || "Loading order…"}</div>;
  }

  return (
    <section className="content-section page-section order-detail-page">
      <BuyerNav path="/orders" navigate={navigate} />
      <button className="text-link" onClick={() => navigate("/orders")}>
        ← Back to orders
      </button>
      <div className="order-detail-header">
        <div>
          <span className="section-kicker">Marketplace order</span>
          <h1>{order.order_number}</h1>
          <p>Placed {new Date(order.placed_at).toLocaleString()}</p>
        </div>
        <div>
          <StatusBadge status={order.status} />
          <strong>LKR {Number(order.grand_total).toLocaleString()}</strong>
        </div>
      </div>
      {message && <div className="alert alert-error">{message}</div>}

      {(order.setups?.length > 0 || order.payment_method) && (
        <section className="order-setup-receipt">
          <div>
            <span className="section-kicker">HexBot setup receipt</span>
            {order.setups?.length > 0 ? order.setups.map((setup) => (
              <article key={setup.public_id}>
                <strong>{setup.name}</strong>
                <small>Setup ID {setup.public_id} · {setup.item_count} products from {setup.shop_count} {setup.shop_count === 1 ? "seller" : "sellers"}</small>
              </article>
            )) : <p>This order was created from a regular marketplace cart.</p>}
          </div>
          <div className="order-payment-receipt">
            <span>Payment representation</span>
            <strong>{paymentLabels[order.payment_method] || order.payment_method}</strong>
            <small>{order.payment_status === "simulated_authorized" ? "Simulated authorization recorded" : "No payment collected"}</small>
          </div>
        </section>
      )}

      <div className="order-address-snapshot">
        <span className="section-kicker">Delivery snapshot</span>
        <strong>{order.delivery_address.recipient_name}</strong>
        <p>
          {order.delivery_address.address_line_1}
          {order.delivery_address.address_line_2
            ? `, ${order.delivery_address.address_line_2}`
            : ""}
          , {order.delivery_address.city}, {order.delivery_address.district}
        </p>
        <small>{order.delivery_address.phone}</small>
      </div>

      <div className="sub-order-list">
        {order.sub_orders.map((subOrder) => (
          <section className="sub-order-card" key={subOrder.id}>
            <div className="sub-order-heading">
              <div>
                <span className="section-kicker">Seller delivery</span>
                <button onClick={() => navigate(`/shops/${subOrder.shop_id}`)}>
                  {subOrder.shop_name}
                </button>
                <small>{subOrder.sub_order_number}</small>
              </div>
              <div>
                <StatusBadge status={subOrder.status} />
                <strong>LKR {Number(subOrder.gross_total).toLocaleString()}</strong>
              </div>
            </div>

            <div className="order-progress">
              {["pending", "processing", "shipped", "completed"].map((status, index) => {
                const currentIndex = ["pending", "processing", "shipped", "completed"]
                  .indexOf(subOrder.status);
                return (
                  <div
                    className={
                      subOrder.status === "cancelled"
                        ? ""
                        : index <= currentIndex
                          ? "complete"
                          : ""
                    }
                    key={status}
                  >
                    <span>{index + 1}</span>
                    <strong>{status}</strong>
                  </div>
                );
              })}
            </div>
            {(subOrder.status === "shipped" || subOrder.status === "completed") && (
              <div className="buyer-shipment-evidence">
                <div><span>Delivery method</span><strong>{!subOrder.delivery_method ? "Not recorded" : subOrder.delivery_method === "third_party_courier" ? "Third-party courier" : "Seller delivery"}</strong></div>
                <div><span>Delivery partner</span><strong>{subOrder.delivery_partner || subOrder.shop_name}</strong></div>
                <div><span>Tracking reference</span><strong>{subOrder.tracking_reference || "Not supplied"}</strong></div>
                <div><span>Estimated delivery</span><strong>{subOrder.estimated_delivery_date ? new Date(`${subOrder.estimated_delivery_date}T00:00:00`).toLocaleDateString() : "Not recorded"}</strong></div>
                {subOrder.shipment_note && <p>{subOrder.shipment_note}</p>}
              </div>
            )}
            <details className="buyer-delivery-timeline">
              <summary>Delivery event history ({subOrder.history.length})</summary>
              <div><span className="complete">✓</span><p><strong>Order placed</strong><small>{new Date(order.placed_at).toLocaleString()}</small></p></div>
              {subOrder.history.map((event, index) => (
                <div key={`${event.created_at}-${index}`}><span className="complete">✓</span><p><strong>{event.new_status.replaceAll("_", " ")}</strong><small>{new Date(event.created_at).toLocaleString()}{event.reason ? ` · ${event.reason}` : ""}</small></p></div>
              ))}
            </details>
            {subOrder.status === "cancelled" && (
              <div className="alert alert-error">
                Cancelled: {subOrder.cancellation_reason}
              </div>
            )}

            <div className="order-product-list">
              {subOrder.items.map((item) => (
                <article className="order-product-line" key={item.id}>
                  <button
                    className="buyer-line-image"
                    onClick={() => navigate(`/products/${item.product_id}`)}
                  >
                    {item.image_filename ? (
                      <img
                        src={mediaUrl("product-images", item.image_filename)}
                        alt={item.product_name}
                      />
                    ) : (
                      <span>{item.product_name.slice(0, 1)}</span>
                    )}
                  </button>
                  <div>
                    <h3>{item.product_name}</h3>
                    <p>{item.sku} · Quantity {item.quantity}</p>
                    <strong>LKR {Number(item.line_total).toLocaleString()}</strong>
                  </div>
                  <div className="order-item-actions">
                    {subOrder.status === "completed" && !item.review_id && (
                      <button
                        className="button button-ghost"
                        onClick={() => setReviewingItem(
                          reviewingItem === item.id ? null : item.id,
                        )}
                      >
                        Write review
                      </button>
                    )}
                    {item.review_id && (
                      <span className="verified-review-pill">
                        ★ {item.review_rating} verified
                      </span>
                    )}
                    <button
                      className="text-link"
                      onClick={() => setReportingItem(
                        reportingItem === item.id ? null : item.id,
                      )}
                    >
                      Report concern
                    </button>
                  </div>

                  {reviewingItem === item.id && (
                    <form
                      className="inline-buyer-form"
                      onSubmit={async (event) => {
                        event.preventDefault();
                        if (submittingAction) return;
                        setSubmittingAction(`review-${item.id}`);
                        try {
                          await apiRequest(`/order-items/${item.id}/reviews`, {
                            method: "POST",
                            token,
                            body: review,
                          });
                          setReviewingItem(null);
                          showToast("Your verified review is now published.", { type: "success" });
                          await load();
                        } catch (error) {
                          showToast(error.message, { type: "error", duration: 6000 });
                        } finally {
                          setSubmittingAction("");
                        }
                      }}
                    >
                      <h4>Verified purchase review</h4>
                      <label>
                        Rating
                        <select
                          value={review.rating}
                          onChange={(event) =>
                            setReview({ ...review, rating: Number(event.target.value) })
                          }
                        >
                          {[5, 4, 3, 2, 1].map((rating) => (
                            <option value={rating} key={rating}>
                              {rating} stars
                            </option>
                          ))}
                        </select>
                      </label>
                      <label>
                        Title
                        <input
                          value={review.title}
                          onChange={(event) =>
                            setReview({ ...review, title: event.target.value })
                          }
                        />
                      </label>
                      <label className="full-width">
                        Review
                        <textarea
                          value={review.review_text}
                          onChange={(event) =>
                            setReview({ ...review, review_text: event.target.value })
                          }
                        />
                      </label>
                      <button className="button button-primary" disabled={Boolean(submittingAction)}>
                        {submittingAction === `review-${item.id}` ? "Publishing…" : "Publish review"}
                      </button>
                    </form>
                  )}

                  {reportingItem === item.id && (
                    <form
                      className="inline-buyer-form report-form"
                      onSubmit={async (event) => {
                        event.preventDefault();
                        if (submittingAction) return;
                        setSubmittingAction(`report-${item.id}`);
                        try {
                          await apiRequest("/counterfeit-reports", {
                            method: "POST",
                            token,
                            body: {
                              listing_id: item.listing_id,
                              order_item_id: item.id,
                              ...report,
                            },
                          });
                          setReportingItem(null);
                          showToast("Your concern was sent privately to administrators for review.", { type: "success" });
                        } catch (error) {
                          showToast(error.message, { type: "error", duration: 6000 });
                        } finally {
                          setSubmittingAction("");
                        }
                      }}
                    >
                      <h4>Report a product concern</h4>
                      <p>
                        This starts an investigation; it does not automatically accuse
                        the seller.
                      </p>
                      <label>
                        Concern
                        <select
                          value={report.reason_code}
                          onChange={(event) =>
                            setReport({ ...report, reason_code: event.target.value })
                          }
                        >
                          {reportReasons.map(([value, label]) => (
                            <option value={value} key={value}>{label}</option>
                          ))}
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
                      <button className="button button-dark" disabled={Boolean(submittingAction)}>
                        {submittingAction === `report-${item.id}` ? "Submitting…" : "Submit privately"}
                      </button>
                    </form>
                  )}
                </article>
              ))}
            </div>

            <div className="sub-order-actions">
              {subOrder.status === "shipped" && (
                <button
                  className="button button-primary"
                  onClick={() => setConfirmingReceipt(subOrder)}
                >
                  Confirm delivery received
                </button>
              )}
              <button
                className="button button-ghost"
                onClick={() => setComplaintSubOrder(
                  complaintSubOrder === subOrder.id ? null : subOrder.id,
                )}
              >
                Get help with this seller order
              </button>
            </div>

            {complaintSubOrder === subOrder.id && (
              <form
                className="inline-buyer-form"
                onSubmit={async (event) => {
                  event.preventDefault();
                  if (submittingAction) return;
                  setSubmittingAction(`complaint-${subOrder.id}`);
                  try {
                    await apiRequest("/complaints", {
                      method: "POST",
                      token,
                      body: {
                        order_id: order.id,
                        sub_order_id: subOrder.id,
                        shop_id: subOrder.shop_id,
                        ...complaint,
                      },
                    });
                    setComplaintSubOrder(null);
                    showToast("Your support case was submitted for administrator review.", { type: "success" });
                  } catch (error) {
                    showToast(error.message, { type: "error", duration: 6000 });
                  } finally {
                    setSubmittingAction("");
                  }
                }}
              >
                <h4>Open a support case</h4>
                <label>
                  Subject
                  <input
                    value={complaint.subject}
                    required
                    onChange={(event) =>
                      setComplaint({ ...complaint, subject: event.target.value })
                    }
                  />
                </label>
                <label className="full-width">
                  Explain what happened
                  <textarea
                    value={complaint.description}
                    required
                    onChange={(event) =>
                      setComplaint({ ...complaint, description: event.target.value })
                    }
                  />
                </label>
                <button className="button button-primary" disabled={Boolean(submittingAction)}>
                  {submittingAction === `complaint-${subOrder.id}` ? "Submitting…" : "Submit support case"}
                </button>
              </form>
            )}
          </section>
        ))}
      </div>
      {confirmingReceipt && (
        <Modal
          onClose={() => setConfirmingReceipt(null)}
          className="buyer-receipt-modal"
          ariaLabel="Confirm delivery receipt"
        >
            <span className="section-kicker">Receipt confirmation</span>
            <h2>Did you receive this seller delivery?</h2>
            <p><strong>{confirmingReceipt.shop_name}</strong> · {confirmingReceipt.sub_order_number}</p>
            <div className="buyer-receipt-evidence"><span>Tracking reference<strong>{confirmingReceipt.tracking_reference || "Not supplied"}</strong></span><span>Products<strong>{confirmingReceipt.items.length}</strong></span></div>
            <div className="alert alert-info">Confirm only after checking that this parcel arrived. This completes this seller order and releases its simulated seller balance.</div>
            <div className="modal-actions">
              <button className="button button-ghost" onClick={() => setConfirmingReceipt(null)}>Not yet</button>
              <button className="button button-primary" disabled={Boolean(submittingAction)} onClick={async () => {
                if (submittingAction) return;
                const receiptId = confirmingReceipt.id;
                setSubmittingAction(`receipt-${receiptId}`);
                try {
                  const response = await apiRequest(`/sub-orders/${receiptId}/confirm-receipt`, { method: "POST", token });
                  setOrder(response.data.order);
                  setConfirmingReceipt(null);
                  showToast("Delivery confirmed. This seller order is complete and its products can now be reviewed.", { type: "success" });
                } catch (error) {
                  showToast(error.message, { type: "error", duration: 6000 });
                } finally {
                  setSubmittingAction("");
                }
              }}>{submittingAction === `receipt-${confirmingReceipt.id}` ? "Confirming…" : "Yes, I received it"}</button>
            </div>
        </Modal>
      )}
    </section>
  );
}
