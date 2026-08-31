import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";
import StatusBadge from "../../components/StatusBadge";
import Modal from "../../components/Modal";

const listingStatuses = [
  "pending_approval",
  "active",
  "flagged",
  "hidden",
  "rejected",
  "inactive",
  "draft",
];

export default function AdminModerationPage() {
  const { token } = useAuth();
  const [tab, setTab] = useState("listings");
  const [status, setStatus] = useState("pending_approval");
  const [search, setSearch] = useState("");
  const [items, setItems] = useState([]);
  const [review, setReview] = useState(null);
  const [message, setMessage] = useState("");

  const load = () => {
    const path =
      tab === "listings"
        ? `/admin/listings?status=${status}&search=${encodeURIComponent(search)}`
        : `/admin/listing-flags?status=${status}`;
    return apiRequest(path, { token }).then((response) =>
      setItems(
        tab === "listings" ? response.data.listings : response.data.flags,
      ),
    );
  };

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [tab, status, token]);

  const changeTab = (nextTab) => {
    setTab(nextTab);
    setStatus(nextTab === "listings" ? "pending_approval" : "open");
    setItems([]);
    setReview(null);
  };

  const submitDecision = async () => {
    setMessage("");
    try {
      if (tab === "listings") {
        await apiRequest(`/admin/listings/${review.id}/decision`, {
          method: "POST",
          token,
          body: { status: review.status, reason: review.note },
        });
      } else {
        await apiRequest(`/admin/listing-flags/${review.id}/decision`, {
          method: "POST",
          token,
          body: { status: review.status, note: review.note },
        });
      }
      setReview(null);
      setMessage("Moderation decision recorded and added to the audit log.");
      await load();
    } catch (error) {
      setMessage(error.message);
    }
  };

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Marketplace quality</span>
          <h1>Listing moderation</h1>
          <p>
            Administrators make the final decision; automated flags never accuse
            a seller by themselves.
          </p>
        </div>
      </div>

      <div className="admin-tab-bar">
        <button
          className={tab === "listings" ? "active" : ""}
          onClick={() => changeTab("listings")}
        >
          Product listings
        </button>
        <button
          className={tab === "flags" ? "active" : ""}
          onClick={() => changeTab("flags")}
        >
          Automated flags
        </button>
      </div>

      <form
        className="moderation-filter-bar"
        onSubmit={(event) => {
          event.preventDefault();
          load().catch((error) => setMessage(error.message));
        }}
      >
        {tab === "listings" && (
          <input
            placeholder="Search product, model, SKU or shop"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        )}
        <select value={status} onChange={(event) => setStatus(event.target.value)}>
          {(tab === "listings"
            ? listingStatuses
            : ["open", "dismissed", "actioned"]
          ).map((value) => (
            <option value={value} key={value}>
              {value.replaceAll("_", " ")}
            </option>
          ))}
        </select>
        <button className="button button-primary">Refresh queue</button>
      </form>

      {message && <div className="alert alert-info">{message}</div>}

      <section className="admin-panel">
        {items.length === 0 ? (
          <div className="compact-empty">This moderation queue is empty.</div>
        ) : (
          <div className="admin-table-wrap">
            <table className="admin-table moderation-table">
              <thead>
                {tab === "listings" ? (
                  <tr>
                    <th>Product</th>
                    <th>Shop / SKU</th>
                    <th>Price / Stock</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                ) : (
                  <tr>
                    <th>Flag</th>
                    <th>Listing</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                )}
              </thead>
              <tbody>
                {items.map((item) =>
                  tab === "listings" ? (
                    <tr key={item.id}>
                      <td>
                        <strong>{item.product_name}</strong>
                        <small>
                          {item.brand_name} · {item.model} · {item.category_name}
                        </small>
                      </td>
                      <td>
                        {item.shop_name}
                        <small>{item.sku}</small>
                      </td>
                      <td>
                        LKR {Number(item.price).toLocaleString()}
                        <small>
                          {item.quantity_on_hand} in stock · {item.open_flags} open
                          flags
                        </small>
                      </td>
                      <td>
                        <StatusBadge status={item.status} />
                      </td>
                      <td>
                        <button
                          className="table-action"
                          onClick={() =>
                            setReview({
                              id: item.id,
                              title: item.product_name,
                              status:
                                item.status === "pending_approval"
                                  ? "active"
                                  : item.status,
                              note: "",
                            })
                          }
                        >
                          Review
                        </button>
                      </td>
                    </tr>
                  ) : (
                    <tr key={item.id}>
                      <td>
                        <strong>{item.rule_code.replaceAll("_", " ")}</strong>
                        <small>{item.explanation}</small>
                      </td>
                      <td>
                        {item.product_name}
                        <small>
                          {item.shop_name} · {item.sku}
                        </small>
                      </td>
                      <td>
                        <StatusBadge status={item.severity} />
                      </td>
                      <td>
                        <StatusBadge status={item.status} />
                      </td>
                      <td>
                        {item.status === "open" ? (
                          <button
                            className="table-action"
                            onClick={() =>
                              setReview({
                                id: item.id,
                                title: item.product_name,
                                status: "dismissed",
                                note: "",
                              })
                            }
                          >
                            Review
                          </button>
                        ) : (
                          "Completed"
                        )}
                      </td>
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {review && (
        <Modal onClose={() => setReview(null)} ariaLabel="Moderation decision">
            <span className="section-kicker">Moderation decision</span>
            <h2>{review.title}</h2>
            <label>
              Decision
              <select
                value={review.status}
                onChange={(event) =>
                  setReview({ ...review, status: event.target.value })
                }
              >
                {(tab === "listings"
                  ? ["active", "rejected", "hidden", "flagged", "inactive"]
                  : ["dismissed", "actioned"]
                ).map((value) => (
                  <option value={value} key={value}>
                    {value.replaceAll("_", " ")}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Review reason
              <textarea
                rows="5"
                value={review.note}
                placeholder={
                  review.status === "active"
                    ? "Optional approval note"
                    : "Required for this decision"
                }
                onChange={(event) =>
                  setReview({ ...review, note: event.target.value })
                }
              />
            </label>
            <div className="modal-actions">
              <button
                className="button button-ghost"
                onClick={() => setReview(null)}
              >
                Cancel
              </button>
              <button className="button button-primary" onClick={submitDecision}>
                Confirm decision
              </button>
            </div>
        </Modal>
      )}
    </>
  );
}
