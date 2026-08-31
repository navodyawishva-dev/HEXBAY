import { useEffect, useState } from "react";
import { apiDownload, apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";
import StatusBadge from "../../components/StatusBadge";
import Modal from "../../components/Modal";

export default function AdminApplicationsPage() {
  const { token } = useAuth();
  const [status, setStatus] = useState("pending");
  const [applications, setApplications] = useState([]);
  const [review, setReview] = useState(null);
  const [message, setMessage] = useState("");

  const load = () =>
    apiRequest(`/admin/shop-applications?status=${status}`, { token }).then(
      (response) => setApplications(response.data.applications),
    );

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token, status]);

  const decide = async () => {
    setMessage("");
    setReview((current) => current ? { ...current, error: "" } : current);
    try {
      await apiRequest(`/admin/shop-applications/${review.id}/decision`, {
        method: "POST",
        token,
        body: {
          decision: review.decision,
          reason: review.reason,
          notes: review.notes,
        },
      });
      setReview(null);
      setMessage("Shop application decision recorded and seller notified.");
      await load();
    } catch (error) {
      setReview((current) => current ? { ...current, error: error.message } : current);
    }
  };

  const openReview = async (application) => {
    setMessage("");
    try {
      const response = await apiRequest(
        `/admin/shop-applications/${application.id}/documents`,
        { token },
      );
      setReview({
        id: application.id,
        shopName: application.shop_name,
        decision: "approved",
        reason: "",
        notes: "",
        error: "",
        documents: response.data.documents,
      });
    } catch (error) {
      setMessage(error.message);
    }
  };

  const downloadDocument = async (document) => {
    try {
      await apiDownload(
        `/admin/verification-documents/${document.id}/download`,
        { token, fallbackName: document.original_filename },
      );
    } catch (error) {
      setMessage(error.message);
    }
  };

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Verification</span>
          <h1>Shop applications</h1>
          <p>Commission acceptance is visible before approval.</p>
        </div>
        <select value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>
      {message && <div className="alert alert-info">{message}</div>}
      <div className="application-list">
        {applications.length === 0 ? (
          <div className="admin-panel compact-empty">No {status} applications.</div>
        ) : (
          applications.map((application) => (
            <article className="application-card" key={application.id}>
              <div className="application-card-heading">
                <div>
                  <small>Application #{application.id}</small>
                  <h2>{application.shop_name}</h2>
                  <p>{application.owner_name} · {application.owner_email}</p>
                </div>
                <StatusBadge status={application.status} />
              </div>
              <div className="application-details">
                <div><span>Legal name</span><strong>{application.legal_name}</strong></div>
                <div><span>Business reference</span><strong>{application.business_registration_reference}</strong></div>
                <div><span>Contact</span><strong>{application.contact_email}</strong></div>
                <div><span>Submitted</span><strong>{application.submitted_at}</strong></div>
                <div><span>Protected documents</span><strong>{application.document_count}</strong></div>
              </div>
              <div className="commission-proof">
                <span>✓ Commission policy accepted</span>
                <strong>{application.percentage_snapshot}%</strong>
                <small>
                  Terms {application.terms_version} · {application.accepted_at}
                </small>
              </div>
              {application.decision_reason && (
                <div className="application-decision-note">
                  <strong>{application.status === "rejected" ? "Rejection reason" : "Decision reason"}</strong>
                  <span>{application.decision_reason}</span>
                  {application.reviewed_at && <small>Recorded {application.reviewed_at}</small>}
                </div>
              )}
              {status === "pending" && (
                <button
                  className="button button-primary"
                  onClick={() => openReview(application)}
                >
                  Review application
                </button>
              )}
            </article>
          ))
        )}
      </div>

      {review && (
        <Modal onClose={() => setReview(null)} ariaLabel="Review seller application">
            <span className="section-kicker">Administrator decision</span>
            <h2>{review.shopName}</h2>
            <section className="admin-document-review">
              <h3>Protected verification documents</h3>
              {review.documents.length === 0 ? (
                <div className="alert alert-error">
                  Approval is blocked until the seller uploads a business
                  registration or equivalent verification document.
                </div>
              ) : (
                review.documents.map((document) => (
                  <div key={document.id}>
                    <span>
                      <strong>{document.original_filename}</strong>
                      <small>{document.document_type.replaceAll("_", " ")}</small>
                    </span>
                    <button
                      type="button"
                      className="button button-ghost"
                      onClick={() => downloadDocument(document)}
                    >
                      Download privately
                    </button>
                  </div>
                ))
              )}
            </section>
            <label htmlFor="decision">Decision</label>
            <select
              id="decision"
              value={review.decision}
              onChange={(event) => setReview({ ...review, decision: event.target.value })}
            >
              <option value="approved">Approve shop</option>
              <option value="rejected">Reject application</option>
              <option value="suspended">Suspend shop</option>
            </select>
            <label htmlFor="decision-reason">Reason</label>
            <textarea
              id="decision-reason"
              rows="3"
              value={review.reason}
              onChange={(event) => setReview({ ...review, reason: event.target.value })}
              placeholder="Required when rejecting or suspending"
            />
            {review.error && <div className="alert alert-error" role="alert">{review.error}</div>}
            {review.decision !== "approved" && review.reason.trim().length < 5 && (
              <small className="field-help">Enter at least five characters explaining this decision. Applications are retained as audit records rather than permanently deleted.</small>
            )}
            <label htmlFor="review-notes">Private review notes</label>
            <textarea
              id="review-notes"
              rows="3"
              value={review.notes}
              onChange={(event) => setReview({ ...review, notes: event.target.value })}
            />
            <div className="modal-actions">
              <button className="button button-ghost" onClick={() => setReview(null)}>
                Cancel
              </button>
              <button
                className="button button-primary"
                disabled={
                  review.decision === "approved" && review.documents.length === 0
                  || review.decision !== "approved" && review.reason.trim().length < 5
                }
                onClick={decide}
              >
                Confirm decision
              </button>
            </div>
        </Modal>
      )}
    </>
  );
}
