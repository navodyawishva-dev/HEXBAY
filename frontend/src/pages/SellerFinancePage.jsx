import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import SellerNav from "../components/SellerNav";
import StatusBadge from "../components/StatusBadge";

export default function SellerFinancePage({ navigate, path }) {
  const { token } = useAuth();
  const [data, setData] = useState(null);
  const [amount, setAmount] = useState("");
  const [message, setMessage] = useState("");

  const load = () =>
    apiRequest("/seller/finance", { token }).then((response) =>
      setData(response.data),
    );

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token]);

  const requestPayout = async () => {
    setMessage("");
    try {
      await apiRequest("/seller/payouts", {
        method: "POST",
        token,
        body: { amount },
      });
      setAmount("");
      setMessage("Simulated payout request submitted for administrator review.");
      await load();
    } catch (error) {
      setMessage(error.message);
    }
  };

  if (!data) return <div className="route-loading">{message || "Loading sales…"}</div>;

  const money = (value) => `LKR ${Number(value).toLocaleString()}`;

  return (
    <section className="content-section page-section">
      <SellerNav path={path} navigate={navigate} />
      <div className="account-hero">
        <div>
          <span className="section-kicker">Simulated finance</span>
          <h1 className="page-title">Sales and payouts</h1>
          <p>Gross sales, Hexbay commission and net seller balance.</p>
        </div>
      </div>
      {message && <div className="alert alert-info">{message}</div>}
      <div className="seller-metric-grid">
        {[
          ["Gross completed sales", money(data.summary.gross_sales)],
          ["Hexbay commission", money(data.summary.commission)],
          ["Net completed sales", money(data.summary.net_sales)],
          ["Pending fulfilment", money(data.summary.pending_balance)],
          ["Available for payout", money(data.summary.available_balance)],
        ].map(([label, value]) => (
          <article className="metric-card" key={label}>
            <span>{label}</span>
            <strong>{value}</strong>
          </article>
        ))}
      </div>
      <div className="admin-two-column">
        <section className="admin-panel">
          <div className="panel-heading">
            <div>
              <h2>Request simulated payout</h2>
              <p>No real money is transferred in this project.</p>
            </div>
          </div>
          <label>
            Amount (LKR)
            <input
              type="number"
              min="0"
              step="0.01"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
            />
          </label>
          <button className="button button-primary full-button" onClick={requestPayout}>
            Submit payout request
          </button>
          <div className="payout-process-note">
            <strong>What happens next?</strong>
            <span>1. Pending admin review</span>
            <span>2. Approved or rejected with a notification</span>
            <span>3. Approved requests are marked paid after the simulated transfer</span>
          </div>
          <div className="payout-list">
            {data.payouts.map((payout) => (
              <div key={payout.id}>
                <span>
                  <strong>{payout.payout_reference}</strong>
                  <small>{payout.requested_at}</small>
                </span>
                <strong>{money(payout.amount)}</strong>
                <StatusBadge status={payout.status} />
              </div>
            ))}
          </div>
        </section>
        <section className="admin-panel">
          <div className="panel-heading">
            <div>
              <h2>Commission ledger</h2>
              <p>Append-only simulated business records.</p>
            </div>
          </div>
          <div className="ledger-list">
            {data.ledger.length === 0 ? (
              <div className="compact-empty">No ledger entries yet.</div>
            ) : (
              data.ledger.map((entry) => (
                <div key={entry.id}>
                  <span>
                    <strong>{entry.description}</strong>
                    <small>{entry.created_at}</small>
                  </span>
                  <strong>{money(entry.amount)}</strong>
                </div>
              ))
            )}
          </div>
        </section>
      </div>
    </section>
  );
}
