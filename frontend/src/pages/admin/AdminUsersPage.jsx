import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";
import StatusBadge from "../../components/StatusBadge";
import Modal from "../../components/Modal";

export default function AdminUsersPage() {
  const { token } = useAuth();
  const [data, setData] = useState({ items: [], pagination: {} });
  const [filters, setFilters] = useState({ role: "", status: "", search: "" });
  const [message, setMessage] = useState("");
  const [action, setAction] = useState(null);

  const load = () => {
    const query = new URLSearchParams(
      Object.fromEntries(Object.entries(filters).filter(([, value]) => value)),
    );
    return apiRequest(`/admin/users?${query}`, { token }).then((response) =>
      setData(response.data),
    );
  };

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token, filters.role, filters.status]);

  const submitSearch = (event) => {
    event.preventDefault();
    load().catch((error) => setMessage(error.message));
  };

  const updateStatus = async () => {
    setMessage("");
    try {
      await apiRequest(`/admin/users/${action.user.id}/status`, {
        method: "POST",
        token,
        body: { status: action.status, reason: action.reason },
      });
      setAction(null);
      setMessage("Account status updated.");
      await load();
    } catch (error) {
      setMessage(error.message);
    }
  };

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Accounts</span>
          <h1>Customers and sellers</h1>
          <p>Manage access without exposing private cross-vendor information.</p>
        </div>
      </div>
      <form className="admin-filter-bar" onSubmit={submitSearch}>
        <input
          placeholder="Search email, name or business"
          value={filters.search}
          onChange={(event) => setFilters({ ...filters, search: event.target.value })}
        />
        <select
          value={filters.role}
          onChange={(event) => setFilters({ ...filters, role: event.target.value })}
        >
          <option value="">All account types</option>
          <option value="customer">Buyers</option>
          <option value="shop_owner">Sellers</option>
        </select>
        <select
          value={filters.status}
          onChange={(event) => setFilters({ ...filters, status: event.target.value })}
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="deactivated">Deactivated</option>
        </select>
        <button className="button button-primary">Search</button>
      </form>
      {message && <div className="alert alert-info">{message}</div>}
      <section className="admin-panel">
        <div className="admin-table-wrap">
          <table className="admin-table">
            <thead>
              <tr>
                <th>Account</th><th>Type</th><th>Shop</th><th>Status</th><th>Action</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((managedUser) => (
                <tr key={managedUser.id}>
                  <td>
                    <strong>
                      {[managedUser.first_name, managedUser.last_name]
                        .filter(Boolean)
                        .join(" ") || managedUser.business_name}
                    </strong>
                    <small>{managedUser.email}</small>
                  </td>
                  <td>{managedUser.role === "shop_owner" ? "Seller" : "Buyer"}</td>
                  <td>
                    {managedUser.shop_name || "—"}
                    {managedUser.shop_status && (
                      <small><StatusBadge status={managedUser.shop_status} /></small>
                    )}
                  </td>
                  <td><StatusBadge status={managedUser.status} /></td>
                  <td>
                    <button
                      className="table-action"
                      onClick={() =>
                        setAction({
                          user: managedUser,
                          status:
                            managedUser.status === "active" ? "suspended" : "active",
                          reason: "",
                        })
                      }
                    >
                      Manage
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {action && (
        <Modal onClose={() => setAction(null)} ariaLabel="Manage customer account">
            <span className="section-kicker">Account action</span>
            <h2>{action.user.email}</h2>
            <label htmlFor="account-status">New status</label>
            <select
              id="account-status"
              value={action.status}
              onChange={(event) => setAction({ ...action, status: event.target.value })}
            >
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="deactivated">Deactivated</option>
            </select>
            <label htmlFor="account-reason">Reason</label>
            <textarea
              id="account-reason"
              rows="4"
              value={action.reason}
              onChange={(event) => setAction({ ...action, reason: event.target.value })}
              placeholder="Required for suspension or deactivation"
            />
            <div className="modal-actions">
              <button className="button button-ghost" onClick={() => setAction(null)}>
                Cancel
              </button>
              <button className="button button-primary" onClick={updateStatus}>
                Save status
              </button>
            </div>
        </Modal>
      )}
    </>
  );
}
