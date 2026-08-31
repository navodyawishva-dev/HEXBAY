import { useEffect, useMemo, useState } from "react";
import { apiRequest } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import { useAuth } from "../contexts/AuthContext";
import { useToast } from "../contexts/ToastContext";

const notificationKinds = {
  order_placed: ["ORD", "Order placed"],
  order_processing: ["BOX", "Seller preparing"],
  order_shipped: ["VAN", "Delivery dispatched"],
  order_cancelled: ["!", "Seller delivery cancelled"],
  delivery_confirmed: ["✓", "Delivery confirmed"],
};

export default function BuyerNotificationsPage({ navigate, path = "/notifications" }) {
  const { token } = useAuth();
  const { showToast } = useToast();
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [filter, setFilter] = useState("all");
  const [message, setMessage] = useState("");
  const [refreshing, setRefreshing] = useState(false);
  const [markingAllRead, setMarkingAllRead] = useState(false);

  const load = async (quiet = false) => {
    if (!quiet) setRefreshing(true);
    try {
      const response = await apiRequest("/notifications", { token });
      setNotifications(response.data.notifications);
      setUnreadCount(Number(response.data.unread_count || 0));
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

  const visible = useMemo(() => notifications.filter((notification) => {
    if (filter === "unread") return !notification.read_at;
    if (filter === "delivery") return ["order_processing", "order_shipped", "delivery_confirmed", "order_cancelled"].includes(notification.type);
    return true;
  }), [filter, notifications]);

  const markRead = async (notification) => {
    if (!notification.read_at) {
      await apiRequest(`/notifications/${notification.id}/read`, { method: "POST", token });
      setNotifications((current) => current.map((item) => item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item));
      setUnreadCount((count) => Math.max(0, count - 1));
    }
  };

  const openNotification = async (notification) => {
    try {
      await markRead(notification);
      if (notification.order_id) navigate(`/orders/${notification.order_id}`);
    } catch (error) {
      setMessage(error.message);
    }
  };

  const markAllRead = async () => {
    if (markingAllRead) return;
    setMarkingAllRead(true);
    try {
      await apiRequest("/notifications/read-all", { method: "POST", token });
      setNotifications((current) => current.map((item) => ({
        ...item,
        read_at: item.read_at || new Date().toISOString(),
      })));
      setUnreadCount(0);
      showToast("All notifications marked as read.", { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setMarkingAllRead(false);
    }
  };

  return (
    <section className="content-section page-section buyer-notification-centre">
      <BuyerNav path={path} navigate={navigate} unreadCount={unreadCount} />
      <div className="section-heading buyer-page-heading">
        <div><span className="section-kicker">Live order events</span><h1 className="page-title">Notifications</h1><p>Seller preparation, dispatch, cancellation, and receipt updates appear here.</p></div>
        <strong>{unreadCount} unread</strong>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      <div className="notification-centre-toolbar">
        <div>{[["all", "All"], ["unread", "Unread"], ["delivery", "Delivery updates"]].map(([value, label]) => <button className={filter === value ? "active" : ""} onClick={() => setFilter(value)} key={value}>{label}</button>)}</div>
        <div><button className="button button-ghost" disabled={refreshing} onClick={() => load()}>{refreshing ? "Refreshing…" : "Refresh"}</button><button className="button button-dark" disabled={unreadCount === 0 || markingAllRead} onClick={markAllRead}>{markingAllRead ? "Saving…" : "Mark all read"}</button></div>
      </div>
      {visible.length === 0 ? (
        <div className="empty-marketplace"><h3>{filter === "unread" ? "You are all caught up" : "No updates yet"}</h3><p>Tracking events will appear as each seller prepares and dispatches your products.</p><button className="button button-primary" onClick={() => navigate("/orders")}>Track orders</button></div>
      ) : (
        <div className="notification-centre-list">
          {visible.map((notification) => {
            const [symbol, label] = notificationKinds[notification.type] || ["HEX", "Hexbay update"];
            return <article className={notification.read_at ? "" : "unread"} key={notification.id}>
              <span className="notification-event-icon">{symbol}</span>
              <div><small>{label} · {new Date(notification.created_at).toLocaleString()}</small><h2>{notification.title}</h2><p>{notification.message}</p></div>
              <div>{!notification.read_at && <span className="notification-new-pill">New</span>}<button className="button button-ghost" onClick={() => openNotification(notification)}>{notification.order_id ? "Open order" : "Mark read"}</button></div>
            </article>;
          })}
        </div>
      )}
      <small className="notification-refresh-note">This page checks for new local updates every 30 seconds.</small>
    </section>
  );
}
