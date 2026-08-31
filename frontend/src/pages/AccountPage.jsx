import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import StatusBadge from "../components/StatusBadge";
import { useAuth } from "../contexts/AuthContext";

export default function AccountPage({ navigate, path = "/account" }) {
  const { user, token, logout } = useAuth();
  const [data, setData] = useState({
    orders: [],
    addresses: [],
    wishlist: { items: [] },
    cart: { summary: { quantity: 0, subtotal: "0.00" } },
    notifications: [],
    unread_count: 0,
  });
  const [message, setMessage] = useState("");

  useEffect(() => {
    Promise.all([
      apiRequest("/orders", { token }),
      apiRequest("/customers/me/addresses", { token }),
      apiRequest("/wishlist/items", { token }),
      apiRequest("/cart", { token }),
      apiRequest("/notifications", { token }),
    ])
      .then(([orders, addresses, wishlist, cart, notifications]) => {
        setData({
          orders: orders.data.orders,
          addresses: addresses.data.addresses,
          wishlist: wishlist.data.wishlist,
          cart: cart.data.cart,
          notifications: notifications.data.notifications,
          unread_count: Number(notifications.data.unread_count || 0),
        });
      })
      .catch((error) => setMessage(error.message));
  }, [token]);

  return (
    <section className="content-section page-section buyer-account-page">
      <BuyerNav path={path} navigate={navigate} unreadCount={data.unread_count} />
      <div className="account-hero">
        <div>
          <span className="section-kicker">Buyer account</span>
          <h1 className="page-title">
            Welcome, {user?.first_name || user?.email}
          </h1>
          <p>Your shopping, deliveries and saved technology are together here.</p>
        </div>
        <button
          className="button button-dark"
          onClick={async () => {
            await logout();
            navigate("/");
          }}
        >
          Sign out
        </button>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      <div className="buyer-metric-grid">
        <button onClick={() => navigate("/orders")}>
          <span>Orders</span>
          <strong>{data.orders.length}</strong>
          <small>Track each seller separately</small>
        </button>
        <button onClick={() => navigate("/wishlist")}>
          <span>Wishlist</span>
          <strong>{data.wishlist.items.length}</strong>
          <small>Saved seller offers</small>
        </button>
        <button onClick={() => navigate("/cart")}>
          <span>Cart</span>
          <strong>{data.cart.summary.quantity}</strong>
          <small>LKR {Number(data.cart.summary.subtotal).toLocaleString()}</small>
        </button>
        <button onClick={() => navigate("/addresses")}>
          <span>Addresses</span>
          <strong>{data.addresses.length}</strong>
          <small>Saved delivery locations</small>
        </button>
      </div>

      <div className="buyer-dashboard-columns">
        <section className="admin-panel">
          <div className="panel-title-row">
            <div>
              <span className="section-kicker">Recent activity</span>
              <h2>Orders</h2>
            </div>
            <button className="text-link" onClick={() => navigate("/orders")}>
              View all
            </button>
          </div>
          {data.orders.length === 0 ? (
            <div className="compact-empty">Your first order will appear here.</div>
          ) : (
            <div className="buyer-order-mini-list">
              {data.orders.slice(0, 4).map((order) => (
                <button
                  type="button"
                  onClick={() => navigate(`/orders/${order.id}`)}
                  key={order.id}
                >
                  <div>
                    <strong>{order.order_number}</strong>
                    <small>{new Date(order.placed_at).toLocaleDateString()}</small>
                  </div>
                  <StatusBadge status={order.status} />
                  <span>LKR {Number(order.grand_total).toLocaleString()}</span>
                </button>
              ))}
            </div>
          )}
        </section>
        <section className="admin-panel">
          <div className="panel-title-row">
            <div>
              <span className="section-kicker">Updates</span>
              <h2>Notifications</h2>
            </div>
            <span className="notification-count">{data.unread_count} unread</span>
          </div>
          {data.notifications.length === 0 ? (
            <div className="compact-empty">No notifications yet.</div>
          ) : (
            <div className="buyer-notification-list">
              {data.notifications.slice(0, 6).map((notification) => (
                <button
                  type="button"
                  className={notification.read_at ? "" : "unread"}
                  key={notification.id}
                  onClick={async () => {
                    if (!notification.read_at) {
                      await apiRequest(`/notifications/${notification.id}/read`, {
                        method: "POST",
                        token,
                      });
                      setData((current) => ({
                        ...current,
                        notifications: current.notifications.map((item) =>
                          item.id === notification.id
                            ? { ...item, read_at: new Date().toISOString() }
                            : item,
                        ),
                        unread_count: Math.max(0, current.unread_count - 1),
                      }));
                    }
                    if (notification.order_id) {
                      navigate(`/orders/${notification.order_id}`);
                    }
                  }}
                >
                  <strong>{notification.title}</strong>
                  <span>{notification.message}</span>
                  <small>{new Date(notification.created_at).toLocaleString()}</small>
                </button>
              ))}
            </div>
          )}
          <button className="text-link centered-link" onClick={() => navigate("/notifications")}>Open notification centre</button>
        </section>
      </div>
    </section>
  );
}
