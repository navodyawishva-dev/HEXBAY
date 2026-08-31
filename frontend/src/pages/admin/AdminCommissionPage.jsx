import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";

export default function AdminCommissionPage() {
  const { token } = useAuth();
  const [rules, setRules] = useState([]);
  const [finance, setFinance] = useState({ summary: {}, payouts: [] });
  const [form, setForm] = useState({ percentage: "5.00", reason: "" });
  const [message, setMessage] = useState("");
  const [payoutReason, setPayoutReason] = useState({});
  const [updatingPayout, setUpdatingPayout] = useState(null);

  const money = (value) => `LKR ${Number(value ?? 0).toLocaleString("en-LK", { minimumFractionDigits: 2 })}`;

  const load = () =>
    Promise.all([
      apiRequest("/admin/commission-rules", { token }),
      apiRequest("/admin/finance", { token }),
    ]).then(([rulesResponse, financeResponse]) => {
      setRules(rulesResponse.data.rules);
      setFinance(financeResponse.data);
      if (rulesResponse.data.rules[0]) {
        setForm((current) => ({
          ...current,
          percentage: rulesResponse.data.rules[0].percentage,
        }));
      }
    });

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token]);

  const submit = async (event) => {
    event.preventDefault();
    setMessage("");
    try {
      const response = await apiRequest("/admin/commission-rules", {
        method: "POST",
        token,
        body: form,
      });
      setMessage(response.message);
      setForm((current) => ({ ...current, reason: "" }));
      await load();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const decidePayout = async (payout, decision) => {
    if (updatingPayout) return;
    setUpdatingPayout(payout.id);
    setMessage("");
    try {
      const response = await apiRequest(`/admin/payouts/${payout.id}/decision`, {
        method: "POST",
        token,
        body: { decision, reason: payoutReason[payout.id] ?? "" },
      });
      setMessage(response.message);
      setPayoutReason((current) => ({ ...current, [payout.id]: "" }));
      await load();
    } catch (error) {
      setMessage(error.message);
    } finally {
      setUpdatingPayout(null);
    }
  };

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Simulated finance</span>
          <h1>Finance, commission and payouts</h1>
          <p>See Hexbay earnings, manage the commission rule, and process seller payout requests.</p>
        </div>
      </div>
      {message && <div className="alert alert-info">{message}</div>}
      <section className="admin-finance-metrics" aria-label="Platform finance summary">
        {[
          ["Completed marketplace sales", money(finance.summary.completed_gross_sales)],
          ["Hexbay commission earned", money(finance.summary.commission_earned)],
          ["Seller net earnings", money(finance.summary.seller_net_earned)],
          ["Pending payout requests", `${finance.summary.pending_payout_count ?? 0} · ${money(finance.summary.pending_payout_amount)}`],
          ["Approved for payout", money(finance.summary.approved_payout_amount)],
          ["Simulated payouts completed", money(finance.summary.paid_payout_amount)],
        ].map(([label, value]) => (
          <article className="metric-card" key={label}>
            <span>{label}</span>
            <strong>{value}</strong>
          </article>
        ))}
      </section>
      <div className="admin-two-column">
        <form className="admin-panel commission-form" onSubmit={submit}>
          <h2>Create a new effective rate</h2>
          <label htmlFor="commission-percentage">Commission percentage</label>
          <div className="percentage-input">
            <input
              id="commission-percentage"
              type="number"
              min="0"
              max="30"
              step="0.01"
              value={form.percentage}
              onChange={(event) =>
                setForm({ ...form, percentage: event.target.value })
              }
            />
            <span>%</span>
          </div>
          <label htmlFor="commission-reason">Reason for change</label>
          <textarea
            id="commission-reason"
            rows="4"
            value={form.reason}
            onChange={(event) => setForm({ ...form, reason: event.target.value })}
            placeholder="Explain why this rate is being introduced"
          />
          <button className="button button-primary">Activate new rate</button>
          <small>
            Existing order snapshots will retain their original commission rate.
          </small>
        </form>
        <section className="admin-panel">
          <h2>Commission history</h2>
          <div className="rule-list">
            {rules.map((rule, index) => (
              <div className="rule-item" key={rule.id}>
                <div>
                  <strong>{rule.percentage}%</strong>
                  <span>{index === 0 ? "Current" : "Previous"}</span>
                </div>
                <p>{rule.reason || "No reason recorded"}</p>
                <small>
                  {rule.effective_from} → {rule.effective_to || "Present"}
                </small>
              </div>
            ))}
          </div>
        </section>
      </div>
      <section className="admin-panel admin-payout-queue">
        <div className="panel-heading">
          <div>
            <h2>Seller payout queue</h2>
            <p>Approve valid requests, then mark the simulated transfer as paid. Sellers are notified after every decision.</p>
          </div>
        </div>
        {finance.payouts.length === 0 ? (
          <div className="compact-empty">No seller payout requests yet.</div>
        ) : (
          <div className="admin-payout-list">
            {finance.payouts.map((payout) => (
              <article key={payout.id}>
                <div>
                  <strong>{payout.payout_reference}</strong>
                  <span>{payout.shop_name} · {payout.owner_email}</span>
                  <small>Requested {payout.requested_at}</small>
                </div>
                <strong>{money(payout.amount)}</strong>
                <span className={`status-badge status-${payout.status}`}>{payout.status}</span>
                {payout.status === "pending" && (
                  <div className="admin-payout-actions">
                    <input
                      value={payoutReason[payout.id] ?? ""}
                      onChange={(event) => setPayoutReason((current) => ({ ...current, [payout.id]: event.target.value }))}
                      placeholder="Reason only required for rejection"
                    />
                    <button className="button button-primary" disabled={Boolean(updatingPayout)} onClick={() => decidePayout(payout, "approved")}>Approve</button>
                    <button className="button button-danger-outline" disabled={Boolean(updatingPayout) || (payoutReason[payout.id] ?? "").trim().length < 5} onClick={() => decidePayout(payout, "rejected")}>Reject</button>
                  </div>
                )}
                {payout.status === "approved" && (
                  <div className="admin-payout-actions">
                    <button className="button button-primary" disabled={Boolean(updatingPayout)} onClick={() => decidePayout(payout, "paid")}>Mark simulated payout paid</button>
                  </div>
                )}
                {payout.decision_reason && <small className="payout-decision-note">Decision note: {payout.decision_reason}</small>}
              </article>
            ))}
          </div>
        )}
      </section>
    </>
  );
}
