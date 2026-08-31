import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import StatusBadge from "../components/StatusBadge";
import SellerNav from "../components/SellerNav";

export default function SellerDashboardPage({ navigate, path = "/seller/dashboard" }) {
  const { token, user } = useAuth();
  const [application, setApplication] = useState(null);
  const [commission, setCommission] = useState(null);
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [dashboard, setDashboard] = useState(null);

  const load = () =>
    Promise.all([
      apiRequest("/seller/shop-application", { token }),
      apiRequest("/commission/current"),
      apiRequest("/notifications", { token }),
      apiRequest("/seller/dashboard", { token }).catch(() => ({ data: null })),
    ])
      .then(
        ([
          applicationResponse,
          commissionResponse,
          notificationResponse,
          dashboardResponse,
        ]) => {
        setApplication(applicationResponse.data.application);
        setCommission(commissionResponse.data.commission);
        setNotifications(notificationResponse.data.notifications);
        setDashboard(dashboardResponse.data);
      },
      )
      .finally(() => setLoading(false));

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token]);

  if (loading) return <div className="route-loading">Loading seller dashboard…</div>;

  if (!application) {
    return (
      <section className="content-section page-section narrow-section">
        <span className="section-kicker">Seller account ready</span>
        <h1 className="page-title">Welcome, {user?.first_name}</h1>
        <div className="application-summary">
          <h2>Your shop application is the next step</h2>
          <p>
            Review the commission policy and submit your technology shop for
            administrator verification.
          </p>
          <button
            className="button button-primary"
            onClick={() => navigate("/seller/onboarding")}
          >
            Start shop application
          </button>
        </div>
      </section>
    );
  }

  const needsCommissionAcceptance =
    application.shop_status === "approved" &&
    (application.superseded_at ||
      Number(application.accepted_percentage) !== Number(commission?.percentage));

  const acceptCommission = async () => {
    setMessage("");
    try {
      const response = await apiRequest("/seller/commission/accept", {
        method: "POST",
        token,
        body: { accepted: true },
      });
      setApplication(response.data.application);
      setMessage(response.message);
    } catch (error) {
      setMessage(error.message);
    }
  };

  return (
    <section className="content-section page-section">
      {application.shop_status === "approved" && (
        <SellerNav path={path} navigate={navigate} />
      )}
      <div className="account-hero">
        <div>
          <span className="section-kicker">Seller dashboard</span>
          <h1 className="page-title">{application.shop_name}</h1>
          <p>Manage your approval journey and future marketplace activity.</p>
        </div>
        <StatusBadge status={application.shop_status} />
      </div>

      {message && <div className="alert alert-success">{message}</div>}

      {needsCommissionAcceptance && (
        <div className="commission-update-banner">
          <div>
            <strong>Commission policy updated to {commission.percentage}%</strong>
            <p>Review and accept the latest policy before future selling activity.</p>
          </div>
          <button className="button button-light" onClick={acceptCommission}>
            Accept updated policy
          </button>
        </div>
      )}

      {application.shop_status === "approved" && dashboard && (
        <div className="seller-metric-grid">
          {[
            ["Products", dashboard.counts.products],
            ["Active listings", dashboard.counts.active_products],
            ["Low stock", dashboard.counts.low_stock],
            ["Open orders", dashboard.counts.open_orders],
            ["Gross sales", `LKR ${Number(dashboard.financial.gross_sales).toLocaleString()}`],
            ["Net sales", `LKR ${Number(dashboard.financial.net_sales).toLocaleString()}`],
          ].map(([label, value]) => (
            <article className="metric-card" key={label}>
              <span>{label}</span>
              <strong>{value}</strong>
            </article>
          ))}
        </div>
      )}

      <div className="seller-dashboard-grid">
        <article className="dashboard-panel">
          <div className="panel-heading">
            <h2>Verification status</h2>
            <StatusBadge status={application.verification_status} />
          </div>
          <dl className="detail-list">
            <div><dt>Legal name</dt><dd>{application.legal_name}</dd></div>
            <div><dt>Submitted</dt><dd>{application.submitted_at}</dd></div>
            <div><dt>Commission accepted</dt><dd>{application.accepted_percentage}%</dd></div>
            <div><dt>Terms version</dt><dd>{application.terms_version}</dd></div>
          </dl>
          {application.decision_reason && (
            <div className="decision-reason">
              <strong>Administrator reason</strong>
              <p>{application.decision_reason}</p>
            </div>
          )}
          {application.verification_status === "pending" && (
            <div className="verification-dashboard-action">
              <p>
                Protected documents uploaded:{" "}
                <strong>{application.document_count ?? 0}</strong>
              </p>
              <button
                className="button button-primary"
                onClick={() => navigate("/seller/onboarding")}
              >
                Upload verification documents
              </button>
            </div>
          )}
        </article>

        <article className="dashboard-panel">
          <div className="panel-heading"><h2>Notifications</h2></div>
          {notifications.length === 0 ? (
            <p className="muted">No notifications yet.</p>
          ) : (
            <div className="notification-list">
              {notifications.slice(0, 5).map((notification) => (
                <div className="notification-item" key={notification.id}>
                  <strong>{notification.title}</strong>
                  <p>{notification.message}</p>
                  <small>{notification.created_at}</small>
                </div>
              ))}
            </div>
          )}
        </article>
      </div>

      {application.shop_status === "approved" && (
        <div className="account-tile-grid">
          {[
            ["Products", "Create structured listings.", "/seller/products"],
            ["Inventory", "Adjust stock with movement history.", "/seller/inventory"],
            ["Orders", "Process and ship your own orders.", "/seller/orders"],
            ["Sales & payouts", "View commission and simulated balances.", "/seller/finance"],
          ].map(([title, description, route]) => (
            <button
              className="account-tile seller-action-tile"
              key={title}
              onClick={() => navigate(route)}
            >
              <h2>{title}</h2>
              <p>{description}</p>
              <strong>Open →</strong>
            </button>
          ))}
        </div>
      )}
    </section>
  );
}
