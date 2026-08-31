import { useEffect, useMemo, useState } from "react";
import { apiRequest } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import SellerNav from "../components/SellerNav";
import StatusBadge from "../components/StatusBadge";
import Modal from "../components/Modal";

const checkpointLabels = {
  stock_verified: ["Stock verified", "Confirm every ordered unit is physically available."],
  items_packed: ["Items packed", "Pack and protect every product in this seller order."],
  delivery_address_verified: ["Delivery address verified", "Check the recipient, telephone number, and destination."],
};

const tomorrow = () => {
  const date = new Date();
  date.setDate(date.getDate() + 1);
  return date.toISOString().slice(0, 10);
};

const statusSteps = ["pending", "processing", "shipped", "completed"];

export default function SellerOrdersPage({ navigate, path }) {
  const { token } = useAuth();
  const [status, setStatus] = useState("");
  const [orders, setOrders] = useState([]);
  const [review, setReview] = useState(null);
  const [busyCheckpoint, setBusyCheckpoint] = useState("");
  const [message, setMessage] = useState("");
  const [messageType, setMessageType] = useState("info");

  const load = () => apiRequest("/seller/orders", { token })
    .then((response) => setOrders(response.data.orders));

  useEffect(() => {
    load().catch((error) => {
      setMessageType("error");
      setMessage(error.message);
    });
  }, [token]);

  const metrics = useMemo(() => ({
    total: orders.length,
    waiting: orders.filter((order) => order.status === "pending").length,
    preparing: orders.filter((order) => order.status === "processing").length,
    shipped: orders.filter((order) => order.status === "shipped").length,
  }), [orders]);
  const displayedOrders = useMemo(
    () => status ? orders.filter((order) => order.status === status) : orders,
    [orders, status],
  );

  const openTransition = (order, nextStatus) => setReview({
    id: order.id,
    title: order.sub_order_number,
    nextStatus,
    reason: "",
    delivery_method: "seller_delivery",
    delivery_partner: "",
    tracking_reference: "",
    estimated_delivery_date: tomorrow(),
    shipment_note: "",
  });

  const updateStatus = async () => {
    setMessage("");
    try {
      await apiRequest(`/seller/orders/${review.id}/status`, {
        method: "POST",
        token,
        body: {
          status: review.nextStatus,
          reason: review.reason,
          delivery_method: review.delivery_method,
          delivery_partner: review.delivery_partner,
          tracking_reference: review.tracking_reference,
          estimated_delivery_date: review.estimated_delivery_date,
          shipment_note: review.shipment_note,
        },
      });
      setReview(null);
      setMessageType("info");
      setMessage(review.nextStatus === "shipped" ? "Shipment recorded and the customer was notified." : "Order status updated.");
      await load();
    } catch (error) {
      setMessageType("error");
      setMessage(error.message);
    }
  };

  const completeCheckpoint = async (orderId, checkpointCode) => {
    setBusyCheckpoint(`${orderId}-${checkpointCode}`);
    setMessage("");
    try {
      await apiRequest(`/seller/orders/${orderId}/fulfilment-checkpoints`, {
        method: "POST",
        token,
        body: { checkpoint_code: checkpointCode },
      });
      setMessageType("info");
      setMessage("Fulfilment check recorded.");
      await load();
    } catch (error) {
      setMessageType("error");
      setMessage(error.message);
    } finally {
      setBusyCheckpoint("");
    }
  };

  return (
    <section className="content-section page-section seller-fulfilment-page">
      <SellerNav path={path} navigate={navigate} />
      <div className="account-hero seller-fulfilment-heading">
        <div>
          <span className="section-kicker">Seller fulfilment workspace</span>
          <h1 className="page-title">Prepare and dispatch orders</h1>
          <p>Your shop sees only its own products and the delivery details required to fulfil them.</p>
        </div>
        <select aria-label="Filter seller orders" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">All orders</option>
          <option value="pending">Awaiting acceptance</option>
          <option value="processing">Preparing</option>
          <option value="shipped">Shipped</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      <div className="seller-fulfilment-metrics">
        <button className={!status ? "active" : ""} onClick={() => setStatus("")}><span>Visible orders</span><strong>{metrics.total}</strong></button>
        <button className={status === "pending" ? "active" : ""} onClick={() => setStatus("pending")}><span>Awaiting acceptance</span><strong>{metrics.waiting}</strong></button>
        <button className={status === "processing" ? "active" : ""} onClick={() => setStatus("processing")}><span>Preparing now</span><strong>{metrics.preparing}</strong></button>
        <button className={status === "shipped" ? "active" : ""} onClick={() => setStatus("shipped")}><span>Awaiting buyer receipt</span><strong>{metrics.shipped}</strong></button>
      </div>

      {message && <div className={`alert alert-${messageType}`}>{message}</div>}
      <div className="seller-order-list seller-fulfilment-list">
        {displayedOrders.length === 0 ? (
          <div className="admin-panel compact-empty">No seller orders match this status yet.</div>
        ) : displayedOrders.map((order) => {
          const currentStep = statusSteps.indexOf(order.status);
          const completedChecks = order.fulfilment_checkpoints.filter((checkpoint) => checkpoint.is_complete).length;
          return (
            <article className="seller-fulfilment-card" key={order.id}>
              <header className="seller-fulfilment-card-header">
                <div>
                  <span className="section-kicker">{order.order_number}</span>
                  <h2>{order.sub_order_number}</h2>
                  <small>Placed {new Date(order.placed_at).toLocaleString()}</small>
                </div>
                <div><StatusBadge status={order.status} /><strong>LKR {Number(order.gross_total).toLocaleString()}</strong></div>
              </header>

              <div className="seller-fulfilment-progress" aria-label={`Order status ${order.status}`}>
                {statusSteps.map((step, index) => (
                  <div className={order.status !== "cancelled" && index <= currentStep ? "complete" : ""} key={step}>
                    <span>{index + 1}</span><strong>{step === "pending" ? "Accepted" : step}</strong>
                  </div>
                ))}
              </div>

              <div className="seller-fulfilment-body">
                <section>
                  <div className="seller-fulfilment-section-heading"><div><span className="section-kicker">Products to pack</span><h3>{order.items.length} order lines</h3></div></div>
                  <div className="order-item-list seller-packing-list">
                    {order.items.map((item) => (
                      <div key={`${order.id}-${item.sku_snapshot}`}>
                        <span><strong>{item.product_name_snapshot}</strong><small>SKU {item.sku_snapshot}</small></span>
                        <span>{item.quantity} × LKR {Number(item.unit_price).toLocaleString()}</span>
                        <strong>LKR {Number(item.line_total).toLocaleString()}</strong>
                      </div>
                    ))}
                  </div>
                </section>

                <section className="seller-delivery-panel">
                  <span className="section-kicker">Delivery destination</span>
                  <h3>{order.delivery_address.recipient_name || "Customer"}</h3>
                  <p>{order.delivery_address.address_line_1 || order.delivery_address.line_1}{order.delivery_address.address_line_2 ? `, ${order.delivery_address.address_line_2}` : ""}</p>
                  <p>{order.delivery_address.city}, {order.delivery_address.district}</p>
                  <strong>{order.delivery_address.phone || order.delivery_address.telephone}</strong>
                  <small>Customer account: {order.customer_email}</small>
                </section>
              </div>

              {order.status === "pending" && (
                <div className="seller-fulfilment-callout">
                  <div><strong>Accept this seller order</strong><span>Inventory was deducted at checkout. Accepting starts the packing workflow.</span></div>
                  <div><button className="button button-primary" onClick={() => openTransition(order, "processing")}>Accept and start</button><button className="button button-ghost" onClick={() => openTransition(order, "cancelled")}>Cannot fulfil</button></div>
                </div>
              )}

              {order.status === "processing" && (
                <section className="seller-checkpoint-panel">
                  <div className="seller-fulfilment-section-heading">
                    <div><span className="section-kicker">Required packing evidence</span><h3>{completedChecks} of 3 checks complete</h3></div>
                    <span className={order.fulfilment_ready_to_ship ? "checkout-ready-pill" : "checkout-demo-pill"}>{order.fulfilment_ready_to_ship ? "Ready to ship" : "In progress"}</span>
                  </div>
                  <div className="seller-checkpoint-list">
                    {order.fulfilment_checkpoints.map((checkpoint) => {
                      const [title, description] = checkpointLabels[checkpoint.code];
                      const busy = busyCheckpoint === `${order.id}-${checkpoint.code}`;
                      return (
                        <div className={checkpoint.is_complete ? "complete" : ""} key={checkpoint.code}>
                          <span className="seller-check-icon">{checkpoint.is_complete ? "✓" : ""}</span>
                          <div><strong>{title}</strong><small>{checkpoint.is_complete ? `Recorded ${new Date(checkpoint.completed_at).toLocaleString()}` : description}</small></div>
                          <button disabled={checkpoint.is_complete || busy} onClick={() => completeCheckpoint(order.id, checkpoint.code)}>{checkpoint.is_complete ? "Complete" : busy ? "Saving…" : "Mark complete"}</button>
                        </div>
                      );
                    })}
                  </div>
                  <div className="seller-checkpoint-actions">
                    <button className="button button-primary" disabled={!order.fulfilment_ready_to_ship} onClick={() => openTransition(order, "shipped")}>Add delivery details and dispatch</button>
                    <button className="button button-ghost" onClick={() => openTransition(order, "cancelled")}>Cancel order</button>
                  </div>
                </section>
              )}

              {(order.status === "shipped" || order.status === "completed") && (
                <section className="seller-shipment-receipt">
                  <div><span>Delivery method</span><strong>{!order.delivery_method ? "Not recorded" : order.delivery_method === "third_party_courier" ? "Third-party courier" : "Seller delivery"}</strong></div>
                  <div><span>Delivery partner</span><strong>{order.delivery_partner || "Seller delivery team"}</strong></div>
                  <div><span>Tracking reference</span><strong>{order.tracking_reference || "Not supplied"}</strong></div>
                  <div><span>Estimated delivery</span><strong>{order.estimated_delivery_date ? new Date(`${order.estimated_delivery_date}T00:00:00`).toLocaleDateString() : "Not recorded"}</strong></div>
                  {order.shipment_note && <p>{order.shipment_note}</p>}
                </section>
              )}

              {order.status === "cancelled" && <div className="alert alert-error">Cancelled: {order.cancellation_reason}</div>}

              <details className="seller-fulfilment-history">
                <summary>Audit timeline ({order.history.length} status changes)</summary>
                {order.history.length === 0 ? <p>No status change has been recorded yet.</p> : order.history.map((event, index) => (
                  <div key={`${event.created_at}-${index}`}><span>{event.previous_status || "created"} → {event.new_status}</span><small>{new Date(event.created_at).toLocaleString()}</small>{event.reason && <p>{event.reason}</p>}</div>
                ))}
              </details>
            </article>
          );
        })}
      </div>

      {review && (
        <Modal
          onClose={() => setReview(null)}
          className="seller-shipment-modal"
          ariaLabel="Update seller order"
        >
            <span className="section-kicker">Controlled order transition</span>
            <h2>{review.title}</h2>
            {review.nextStatus === "shipped" ? (
              <>
                <p>Record how this delivery can be identified before notifying the customer.</p>
                <label>Delivery method<select value={review.delivery_method} onChange={(event) => setReview({ ...review, delivery_method: event.target.value })}><option value="seller_delivery">Seller delivery team</option><option value="third_party_courier">Third-party courier</option></select></label>
                {review.delivery_method === "third_party_courier" && <><label>Courier name<input value={review.delivery_partner} required onChange={(event) => setReview({ ...review, delivery_partner: event.target.value })} /></label><label>Tracking reference<input value={review.tracking_reference} required onChange={(event) => setReview({ ...review, tracking_reference: event.target.value })} /></label></>}
                <label>Estimated delivery date<input type="date" min={new Date().toISOString().slice(0, 10)} value={review.estimated_delivery_date} onChange={(event) => setReview({ ...review, estimated_delivery_date: event.target.value })} /></label>
                <label>Customer delivery note<textarea rows="3" value={review.shipment_note} onChange={(event) => setReview({ ...review, shipment_note: event.target.value })} /></label>
              </>
            ) : review.nextStatus === "cancelled" ? (
              <label>Cancellation reason<textarea rows="4" required value={review.reason} onChange={(event) => setReview({ ...review, reason: event.target.value })} /></label>
            ) : <p>Accept this order and begin its verified packing workflow?</p>}
            <div className="modal-actions"><button className="button button-ghost" onClick={() => setReview(null)}>Go back</button><button className="button button-primary" onClick={updateStatus}>{review.nextStatus === "shipped" ? "Notify customer and dispatch" : "Confirm status"}</button></div>
        </Modal>
      )}
    </section>
  );
}
