import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";
import StatusBadge from "../../components/StatusBadge";

export default function AdminDashboardPage({ navigate }) {
  const { token } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    apiRequest("/admin/dashboard", { token })
      .then((response) => setData(response.data))
      .catch((requestError) => setError(requestError.message));
  }, [token]);

  if (!data && !error) return <div className="route-loading">Loading administration…</div>;
  if (error) return <div className="alert alert-error">{error}</div>;

  const cards = [
    ["Customers", data.counts.customers],
    ["Seller accounts", data.counts.sellers],
    ["Pending shops", data.counts.pending_shops],
    ["Approved shops", data.counts.approved_shops],
    ["Suspended accounts", data.counts.suspended_accounts],
    ["Open listing flags", data.counts.open_flags],
    ["Listings awaiting review", data.counts.pending_listings],
    ["Open complaints", data.counts.open_complaints],
    ["Open product reports", data.counts.open_reports],
    ["Commission earned", `LKR ${Number(data.finance?.commission_earned ?? 0).toLocaleString()}`],
    ["Pending payouts", data.finance?.pending_payout_count ?? 0],
  ];

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Marketplace control centre</span>
          <h1>Administrator overview</h1>
          <p>Review marketplace trust, accounts and commission settings.</p>
        </div>
        <div className="active-commission">
          <span>Current commission</span>
          <strong>{data.current_commission?.percentage ?? "—"}%</strong>
        </div>
      </div>

      <div className="metric-grid">
        {cards.map(([label, value]) => (
          <article className="metric-card" key={label}>
            <span>{label}</span>
            <strong>{value}</strong>
          </article>
        ))}
      </div>

      <section className="admin-panel">
        <div className="panel-heading">
          <div>
            <h2>Applications needing review</h2>
            <p>Oldest submissions appear first.</p>
          </div>
          <button className="text-link" onClick={() => navigate("/admin/applications")}>
            Review all →
          </button>
        </div>
        {data.pending_applications.length === 0 ? (
          <div className="compact-empty">No pending shop applications.</div>
        ) : (
          <div className="admin-table-wrap">
            <table className="admin-table">
              <thead>
                <tr><th>Shop</th><th>Owner</th><th>Submitted</th><th>Status</th></tr>
              </thead>
              <tbody>
                {data.pending_applications.map((application) => (
                  <tr key={application.id}>
                    <td><strong>{application.shop_name}</strong><small>{application.legal_name}</small></td>
                    <td>{application.owner_email}</td>
                    <td>{application.submitted_at}</td>
                    <td><StatusBadge status="pending" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}
