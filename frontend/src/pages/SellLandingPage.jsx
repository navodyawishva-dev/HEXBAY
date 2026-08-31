import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";

export default function SellLandingPage({ navigate }) {
  const [commission, setCommission] = useState(null);

  useEffect(() => {
    apiRequest("/commission/current")
      .then((response) => setCommission(response.data.commission))
      .catch(() => setCommission(null));
  }, []);

  return (
    <section className="seller-landing">
      <div className="seller-landing-copy">
        <span className="section-kicker">Sell on Hexbay</span>
        <h1>A focused marketplace for trusted technology shops.</h1>
        <p>
          Build your verified shop profile, publish structured product listings and
          reach customers looking for the right technology.
        </p>
        <ul className="feature-list">
          <li>One seller dashboard for listings, stock and orders</li>
          <li>Transparent commission and simulated payout records</li>
          <li>Product visibility through comparisons and recommendations</li>
        </ul>
        <button
          className="button button-primary"
          onClick={() => navigate("/register/seller")}
        >
          Create seller account
        </button>
      </div>
      <aside className="commission-card">
        <span>Transparent platform fee</span>
        <strong>{commission ? `${commission.percentage}%` : "—"}</strong>
        <p>
          {commission?.summary ||
            "You review and accept the live commission policy before submitting your shop application."}
        </p>
        <small>The rate is loaded from Hexbay’s active commission rule.</small>
      </aside>
    </section>
  );
}
