import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";
import StatusBadge from "../../components/StatusBadge";
import Modal from "../../components/Modal";

export default function AdminTrustPage() {
  const { token } = useAuth();
  const [tab, setTab] = useState("complaints");
  const [status, setStatus] = useState("open");
  const [items, setItems] = useState([]);
  const [review, setReview] = useState(null);
  const [message, setMessage] = useState("");

  const load = () => {
    const path =
      tab === "complaints"
        ? `/admin/complaints?status=${status}`
        : `/admin/counterfeit-reports?status=${status}`;
    return apiRequest(path, { token }).then((response) =>
      setItems(
        tab === "complaints"
          ? response.data.complaints
          : response.data.reports,
      ),
    );
  };

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [tab, status, token]);

  const changeTab = (nextTab) => {
    setTab(nextTab);
    setStatus("open");
    setItems([]);
    setReview(null);
  };

  const submitDecision = async () => {
    setMessage("");
    try {
      const path =
        tab === "complaints"
          ? `/admin/complaints/${review.id}/decision`
          : `/admin/counterfeit-reports/${review.id}/decision`;
      await apiRequest(path, {
        method: "POST",
        token,
        body: { status: review.status, note: review.note },
      });
      setReview(null);
      setMessage("Trust and safety decision recorded. Relevant users were notified.");
      await load();
    } catch (error) {
      setMessage(error.message);
    }
  };

  const statuses =
    tab === "complaints"
      ? ["open", "under_review", "resolved", "dismissed"]
      : ["open", "under_review", "actioned", "dismissed"];

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Trust and safety</span>
          <h1>Complaints and product reports</h1>
          <p>
            Review concerns fairly, record reasons and notify affected users.
          </p>
        </div>
      </div>

      <div className="admin-tab-bar">
        <button
          className={tab === "complaints" ? "active" : ""}
          onClick={() => changeTab("complaints")}
        >
          Customer complaints
        </button>
        <button
          className={tab === "reports" ? "active" : ""}
          onClick={() => changeTab("reports")}
        >
          Counterfeit reports
        </button>
      </div>

      <div className="queue-toolbar">
        <label>
          Queue status
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            {statuses.map((value) => (
              <option value={value} key={value}>
                {value.replaceAll("_", " ")}
              </option>
            ))}
          </select>
        </label>
        <button
          className="button button-primary"
          onClick={() => load().catch((error) => setMessage(error.message))}
        >
          Refresh queue
        </button>
      </div>

      {message && <div className="alert alert-info">{message}</div>}

      <div className="trust-queue">
        {items.length === 0 ? (
          <section className="admin-panel compact-empty">
            No items are waiting in this queue.
          </section>
        ) : (
          items.map((item) => (
            <article className="admin-panel trust-card" key={item.id}>
              <div className="trust-card-heading">
                <div>
                  <small>
                    {tab === "complaints"
                      ? `Complaint #${item.id}`
                      : `Product report #${item.id}`}
                  </small>
                  <h2>
                    {tab === "complaints"
                      ? item.subject
                      : item.product_name}
                  </h2>
                </div>
                <StatusBadge status={item.status} />
              </div>
              <p className="trust-description">{item.description}</p>
              <div className="trust-metadata">
                {tab === "complaints" ? (
                  <>
                    <span>
                      Customer <strong>{item.customer_email}</strong>
                    </span>
                    <span>
                      Shop <strong>{item.shop_name || "Not linked"}</strong>
                    </span>
                    <span>
                      Product <strong>{item.product_name || "Not linked"}</strong>
                    </span>
                  </>
                ) : (
                  <>
                    <span>
                      Reporter <strong>{item.reporter_email}</strong>
                    </span>
                    <span>
                      Shop <strong>{item.shop_name}</strong>
                    </span>
                    <span>
                      Reason{" "}
                      <strong>{item.reason_code.replaceAll("_", " ")}</strong>
                    </span>
                  </>
                )}
              </div>
              {item.resolution_note || item.review_note ? (
                <div className="review-note">
                  <strong>Administrator note</strong>
                  <p>{item.resolution_note || item.review_note}</p>
                </div>
              ) : null}
              {["open", "under_review"].includes(item.status) && (
                <button
                  className="button button-primary"
                  onClick={() =>
                    setReview({
                      id: item.id,
                      title:
                        tab === "complaints" ? item.subject : item.product_name,
                      status:
                        item.status === "open"
                          ? "under_review"
                          : tab === "complaints"
                            ? "resolved"
                            : "actioned",
                      note: "",
                    })
                  }
                >
                  Review item
                </button>
              )}
            </article>
          ))
        )}
      </div>

      {review && (
        <Modal onClose={() => setReview(null)} ariaLabel="Trust decision">
            <span className="section-kicker">Trust decision</span>
            <h2>{review.title}</h2>
            <label>
              New status
              <select
                value={review.status}
                onChange={(event) =>
                  setReview({ ...review, status: event.target.value })
                }
              >
                {(tab === "complaints"
                  ? ["under_review", "resolved", "dismissed"]
                  : ["under_review", "actioned", "dismissed"]
                ).map((value) => (
                  <option value={value} key={value}>
                    {value.replaceAll("_", " ")}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Administrator note
              <textarea
                rows="5"
                value={review.note}
                placeholder="Required when resolving, dismissing or taking action"
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
                Save decision
              </button>
            </div>
        </Modal>
      )}
    </>
  );
}
