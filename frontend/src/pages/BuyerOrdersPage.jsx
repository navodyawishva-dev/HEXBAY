import { useEffect, useMemo, useState } from "react";
import { apiRequest } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import StatusBadge from "../components/StatusBadge";
import { useAuth } from "../contexts/AuthContext";

const deliverySteps = ["pending", "processing", "shipped", "completed"];

const deliveryStatusLabel = (status) => ({
  pending: "Waiting for seller",
  processing: "Seller preparing",
  shipped: "Confirm after delivery",
  completed: "Delivery confirmed",
  cancelled: "Cancelled",
}[status] || status);

export default function BuyerOrdersPage({ navigate, path = "/orders" }) {
  const { token } = useAuth();
  const [orders, setOrders] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [filter, setFilter] = useState("active");
  const [message, setMessage] = useState("");
  const [refreshing, setRefreshing] = useState(false);

  const load = async (quiet = false) => {
    if (!quiet) setRefreshing(true);
    try {
      const [orderResponse, notificationResponse] = await Promise.all([
        apiRequest("/orders", { token }),
        apiRequest("/notifications", { token }),
      ]);
      setOrders(orderResponse.data.orders);
      setUnreadCount(Number(notificationResponse.data.unread_count || 0));
      setMessage("");
    } catch (error) {
      setMessage(error.message);
    } finally {
      if (!quiet) setRefreshing(false);
    }
  };

  useEffect(() => {
    load();
    const timer = window.setInterval(() => load(true), 30000);
    return () => window.clearInterval(timer);
  }, [token]);

  const metrics = useMemo(() => ({
    active: orders.filter((order) => !["completed", "cancelled"].includes(order.status)).length,
    receipt: orders.reduce((sum, order) => sum + Number(order.shipped_delivery_count || 0), 0),
    completed: orders.filter((order) => order.status === "completed").length,
    sellers: orders.reduce((sum, order) => sum + Number(order.seller_count || 0), 0),
  }), [orders]);

  const visible = useMemo(() => orders.filter((order) => {
    if (filter === "active") return !["completed", "cancelled"].includes(order.status);
    if (filter === "receipt") return Number(order.shipped_delivery_count) > 0;
    if (filter === "completed") return order.status === "completed";
    return true;
  }), [filter, orders]);

  return (
    <section className="content-section page-section buyer-tracking-page">
      <BuyerNav path={path} navigate={navigate} unreadCount={unreadCount} />
      <div className="section-heading buyer-page-heading">
        <div><span className="section-kicker">Multi-seller delivery tracking</span><h1 className="page-title">Track your orders</h1><p>Follow each seller delivery independently, then confirm only the parcel you actually received.</p></div>
        <button className="button button-ghost" disabled={refreshing} onClick={() => load()}>{refreshing ? "Refreshing…" : "Refresh tracking"}</button>
      </div>
      {message && <div className="alert alert-error">{message}</div>}

      <div className="buyer-tracking-metrics">
        <button className={filter === "active" ? "active" : ""} onClick={() => setFilter("active")}><span>Active orders</span><strong>{metrics.active}</strong><small>Still being fulfilled</small></button>
        <button className={filter === "receipt" ? "active attention" : "attention"} onClick={() => setFilter("receipt")}><span>Receipt required</span><strong>{metrics.receipt}</strong><small>Shipped seller deliveries</small></button>
        <button className={filter === "completed" ? "active" : ""} onClick={() => setFilter("completed")}><span>Completed</span><strong>{metrics.completed}</strong><small>Confirmed marketplace orders</small></button>
        <button className={filter === "all" ? "active" : ""} onClick={() => setFilter("all")}><span>Seller deliveries</span><strong>{metrics.sellers}</strong><small>Across {orders.length} orders</small></button>
      </div>

      {visible.length === 0 ? (
        <div className="empty-marketplace"><h3>{orders.length === 0 ? "No orders yet" : "Nothing matches this tracking view"}</h3><p>{orders.length === 0 ? "Your orders will appear here immediately after checkout." : "Choose another status above to see the rest of your order history."}</p><button className="button button-primary" onClick={() => navigate(orders.length === 0 ? "/products" : "/notifications")}>{orders.length === 0 ? "Browse products" : "View notifications"}</button></div>
      ) : (
        <div className="buyer-tracking-list">
          {visible.map((order) => (
            <article className="buyer-tracking-card" key={order.id}>
              <header>
                <div><span className="section-kicker">{Number(order.setup_count) > 0 ? "HexBot setup order" : "Marketplace order"}</span><h2>{order.primary_setup_name || order.order_number}</h2><small>{order.order_number} · Placed {new Date(order.placed_at).toLocaleString()}</small></div>
                <div><StatusBadge status={order.status} /><strong>LKR {Number(order.grand_total).toLocaleString()}</strong></div>
              </header>
              <div className="buyer-order-delivery-summary">
                <span>{order.item_count} products</span><span>{order.seller_count} seller deliveries</span>
                {order.next_estimated_delivery_date && <span>Next ETA {new Date(`${order.next_estimated_delivery_date}T00:00:00`).toLocaleDateString()}</span>}
              </div>
              <div className="buyer-delivery-list">
                {order.deliveries.map((delivery) => {
                  const currentStep = deliverySteps.indexOf(delivery.status);
                  return <div className={`buyer-delivery-row ${delivery.status === "shipped" ? "needs-receipt" : ""}`} key={delivery.id}>
                    <div className="buyer-delivery-heading"><div><span>{delivery.sub_order_number}</span><strong>{delivery.shop_name}</strong><small>{delivery.item_count} products · LKR {Number(delivery.gross_total).toLocaleString()}</small></div><StatusBadge status={delivery.status} /></div>
                    <div className="buyer-mini-progress">{deliverySteps.map((step, index) => <i className={delivery.status !== "cancelled" && index <= currentStep ? "complete" : ""} title={step} key={step} />)}</div>
                    <div className="buyer-delivery-footer"><span>{deliveryStatusLabel(delivery.status)}</span>{delivery.estimated_delivery_date && <small>ETA {new Date(`${delivery.estimated_delivery_date}T00:00:00`).toLocaleDateString()}</small>}{delivery.tracking_reference && <small>Tracking {delivery.tracking_reference}</small>}</div>
                  </div>;
                })}
              </div>
              <footer><button className="button button-primary" onClick={() => navigate(`/orders/${order.id}`)}>{Number(order.shipped_delivery_count) > 0 ? "Review delivery and confirm receipt" : "Open detailed tracking"}</button><small>Statuses refresh automatically every 30 seconds.</small></footer>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
