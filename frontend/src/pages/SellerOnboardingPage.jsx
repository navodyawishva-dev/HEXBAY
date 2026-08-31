import { useEffect, useState } from "react";
import { apiRequest, apiUpload } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import FieldError from "../components/FieldError";
import StatusBadge from "../components/StatusBadge";

const emptyForm = {
  shop_name: "",
  description: "",
  address: "",
  contact_phone: "",
  contact_email: "",
  legal_name: "",
  business_registration_reference: "",
  commission_accepted: false,
};

export default function SellerOnboardingPage({ navigate }) {
  const { token, user } = useAuth();
  const [commission, setCommission] = useState(null);
  const [application, setApplication] = useState(null);
  const [form, setForm] = useState({
    ...emptyForm,
    contact_phone: user?.phone ?? "",
    contact_email: user?.email ?? "",
    legal_name: `${user?.first_name ?? ""} ${user?.last_name ?? ""}`.trim(),
    shop_name: user?.business_name ?? "",
  });
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [documents, setDocuments] = useState([]);
  const [documentFiles, setDocumentFiles] = useState([]);
  const [documentInputKey, setDocumentInputKey] = useState(0);
  const [documentType, setDocumentType] = useState("business_registration");
  const [uploadingDocument, setUploadingDocument] = useState(false);

  const loadDocuments = () =>
    apiRequest("/seller/verification-documents", { token }).then((response) =>
      setDocuments(response.data.documents),
    );

  useEffect(() => {
    Promise.all([
      apiRequest("/commission/current"),
      apiRequest("/seller/shop-application", { token }),
    ])
      .then(([commissionResponse, applicationResponse]) => {
        setCommission(commissionResponse.data.commission);
        setApplication(applicationResponse.data.application);
        if (applicationResponse.data.application) {
          loadDocuments().catch((error) => setMessage(error.message));
        }
      })
      .catch((error) => setMessage(error.message))
      .finally(() => setLoading(false));
  }, [token]);

  const update = (event) => {
    const { name, value, checked, type } = event.target;
    setForm((current) => ({
      ...current,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const submit = async (event) => {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    setMessage("");
    try {
      const response = await apiRequest("/seller/shop-application", {
        method: "POST",
        token,
        body: {
          ...form,
          commission_rule_id: commission.id,
        },
      });
      setApplication(response.data.application);
      setMessage(response.message);
      await loadDocuments();
    } catch (error) {
      setMessage(error.message);
      setErrors(error.validationErrors ?? {});
    } finally {
      setSubmitting(false);
    }
  };

  const uploadDocument = async () => {
    if (documentFiles.length === 0) return;
    setUploadingDocument(true);
    setMessage("");
    try {
      for (const file of documentFiles) {
        const formData = new FormData();
        formData.append("file", file);
        formData.append("document_type", documentType);
        await apiUpload("/seller/verification-documents", { formData, token });
      }
      const uploadedCount = documentFiles.length;
      setDocumentFiles([]);
      setDocumentInputKey((current) => current + 1);
      await loadDocuments();
      setMessage(`${uploadedCount} verification ${uploadedCount === 1 ? "document" : "documents"} uploaded securely for administrator review.`);
    } catch (error) {
      setMessage(error.message);
    } finally {
      setUploadingDocument(false);
    }
  };

  if (loading) {
    return <div className="route-loading">Preparing seller onboarding…</div>;
  }

  if (application && application.shop_status !== "rejected") {
    return (
      <section className="content-section page-section narrow-section">
        <span className="section-kicker">Shop application</span>
        <h1 className="page-title">Your application is already submitted</h1>
        <div className="application-summary">
          <StatusBadge status={application.verification_status} />
          <h2>{application.shop_name}</h2>
          <p>
            Commission accepted: {application.accepted_percentage}% · Terms{" "}
            {application.terms_version}
          </p>
          <p>
            You can follow the review status from your seller dashboard. An
            administrator must approve the shop before listings can become public.
          </p>
          <div className="verification-upload-panel">
            <div>
              <strong>Protected verification documents</strong>
              <p>
                These files are private and can only be downloaded by an
                authenticated administrator.
              </p>
              <ul className="verification-requirements">
                <li>
                  <strong>Start with:</strong> business registration certificate
                  or equivalent shop-registration document.
                </li>
                <li>
                  <strong>Supporting documents:</strong> owner ID and proof of
                  business address when the administrator needs confirmation.
                </li>
                <li>
                  Accepted files: PDF, PNG or JPG, up to 8 MB each. Maximum five
                  documents.
                </li>
              </ul>
              <small className="testing-safety-note">
                For local testing, use sample documents without real private
                identity information.
              </small>
            </div>
            <div className="document-list">
              {documents.map((document) => (
                <div key={document.id}>
                  <span>{document.original_filename}</span>
                  <small>
                    {document.document_type.replaceAll("_", " ")} ·{" "}
                    {Math.ceil(Number(document.byte_size) / 1024)} KB
                  </small>
                </div>
              ))}
              {documents.length === 0 && (
                <div className="compact-empty">
                  Upload at least the business-registration document before
                  administrator approval.
                </div>
              )}
            </div>
            {application.verification_status === "pending" && (
              <div className="verification-upload-controls">
                <select
                  value={documentType}
                  onChange={(event) => setDocumentType(event.target.value)}
                >
                  <option value="business_registration">Business registration</option>
                  <option value="identity">Identity document</option>
                  <option value="address_proof">Address proof</option>
                  <option value="other">Other supporting document</option>
                </select>
                <input
                  key={documentInputKey}
                  type="file"
                  multiple
                  accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                  onChange={(event) => setDocumentFiles(
                    Array.from(event.target.files ?? []).slice(0, 5 - documents.length)
                  )}
                />
                <button
                  type="button"
                  className="button button-ghost"
                  disabled={
                    documentFiles.length === 0 ||
                    uploadingDocument ||
                    documents.length >= 5
                  }
                  onClick={uploadDocument}
                >
                  {uploadingDocument
                    ? "Uploading…"
                    : `Upload ${documentFiles.length || "protected"} ${documentFiles.length === 1 ? "file" : "files"}`}
                </button>
                {documentFiles.length > 0 && (
                  <small>{documentFiles.map((file) => file.name).join(", ")}</small>
                )}
              </div>
            )}
          </div>
          <button
            className="button button-primary"
            onClick={() => navigate("/seller/dashboard")}
          >
            Open seller dashboard
          </button>
        </div>
      </section>
    );
  }

  const exampleGross = 100000;
  const exampleCommission =
    exampleGross * (Number(commission?.percentage ?? 0) / 100);
  const formatMoney = (value) =>
    new Intl.NumberFormat("en-LK", {
      style: "currency",
      currency: "LKR",
      minimumFractionDigits: 2,
    }).format(value);

  return (
    <section className="content-section page-section onboarding-section">
      <div className="section-heading">
        <div>
          <span className="section-kicker">Seller onboarding</span>
          <h1 className="page-title">Submit your technology shop</h1>
          <p>Provide accurate business details for administrator review.</p>
        </div>
        <div className="step-pill">Step 2 of 2</div>
      </div>

      {message && <div className={`alert ${errors && Object.keys(errors).length ? "alert-error" : "alert-success"}`}>{message}</div>}

      <form className="onboarding-grid" onSubmit={submit}>
        <div className="form-panel">
          <h2>Shop details</h2>
          {[
            ["shop_name", "Public shop name", "The name customers will see."],
            ["legal_name", "Legal owner or business name", "Enter the registered company, partnership, or proprietor name."],
            ["business_registration_reference", "Business registration reference", "Enter the number printed on the registration certificate or trade licence, for example PV 123456 or BR-2026-00125."],
            ["contact_email", "Shop email address", "A business email the administrator can verify."],
            ["contact_phone", "Shop telephone", "A reachable Sri Lankan business telephone number."],
            ["address", "Shop address", "The physical or registered business address."],
          ].map(([name, label, help]) => (
            <div className="form-field" key={name}>
              <label htmlFor={name}>{label}</label>
              <input
                id={name}
                name={name}
                value={form[name]}
                onChange={update}
                placeholder={name === "business_registration_reference" ? "Example: PV 123456 or BR-2026-00125" : ""}
              />
              <small className="field-help">{help}</small>
              <FieldError errors={errors[name]} />
            </div>
          ))}
          <label htmlFor="description">Shop description</label>
          <textarea
            id="description"
            name="description"
            rows="5"
            value={form.description}
            onChange={update}
            placeholder="Tell customers what your technology shop specialises in."
          />
          <FieldError errors={errors.description} />
        </div>

        <aside className="consent-panel">
          <span className="section-kicker">Transparent commission</span>
          <div className="commission-number">{commission?.percentage}%</div>
          <h2>Hexbay platform commission</h2>
          <p>{commission?.summary}</p>
          <div className="calculation-example">
            <div><span>Example completed sale</span><strong>{formatMoney(exampleGross)}</strong></div>
            <div><span>Hexbay commission</span><strong>{formatMoney(exampleCommission)}</strong></div>
            <div><span>Seller net amount</span><strong>{formatMoney(exampleGross - exampleCommission)}</strong></div>
          </div>
          <label className="consent-checkbox">
            <input
              type="checkbox"
              name="commission_accepted"
              checked={form.commission_accepted}
              onChange={update}
            />
            <span>
              I understand and accept Hexbay’s current {commission?.percentage}%
              commission on completed vendor sub-orders.
            </span>
          </label>
          <FieldError errors={errors.commission_accepted} />
          <small>Terms version: {commission?.terms_version}</small>
          <button className="button button-primary full-button" disabled={submitting}>
            {submitting ? "Submitting…" : "Submit for administrator review"}
          </button>
        </aside>
      </form>
    </section>
  );
}
