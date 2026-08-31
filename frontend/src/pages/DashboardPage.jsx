import { useAuth } from "../contexts/AuthContext";

const roleNames = {
  administrator: "Administrator",
  shop_owner: "Tech Shop Owner",
  customer: "Customer",
};

export default function DashboardPage() {
  const { user, logout } = useAuth();

  return (
    <section className="dashboard-card">
      <div>
        <p className="eyebrow">Authentication verified</p>
        <h1>
          Hello, {user.first_name || user.business_name || user.email}
        </h1>
        <p className="muted">
          You are signed in as {roleNames[user.role] ?? user.role}.
        </p>
      </div>

      <dl className="account-summary">
        <div>
          <dt>Email</dt>
          <dd>{user.email}</dd>
        </div>
        <div>
          <dt>Account status</dt>
          <dd>{user.status}</dd>
        </div>
        <div>
          <dt>Sprint 1 access</dt>
          <dd>Authentication dashboard only</dd>
        </div>
      </dl>

      <div className="notice">
        Marketplace dashboards and features are introduced in later approved sprints.
      </div>

      <button className="secondary-button" type="button" onClick={logout}>
        Sign out
      </button>
    </section>
  );
}

