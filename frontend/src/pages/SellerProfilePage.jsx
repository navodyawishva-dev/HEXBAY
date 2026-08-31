import { useEffect, useRef, useState } from "react";
import { apiRequest, apiUpload, mediaUrl } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import SellerNav from "../components/SellerNav";
import StatusBadge from "../components/StatusBadge";
import Modal from "../components/Modal";
import { useToast } from "../contexts/ToastContext";

export default function SellerProfilePage({ navigate, path }) {
  const { token } = useAuth();
  const { showToast } = useToast();
  const [shop, setShop] = useState(null);
  const [message, setMessage] = useState("");
  const [logoFile, setLogoFile] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [removingLogo, setRemovingLogo] = useState(false);
  const [confirmingLogoRemoval, setConfirmingLogoRemoval] = useState(false);
  const [fileInputKey, setFileInputKey] = useState(0);
  const logoInputRef = useRef(null);

  const load = () =>
    apiRequest("/seller/profile", { token }).then((response) =>
      setShop(response.data.shop),
    );

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token]);

  const save = async (event) => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setMessage("");
    try {
      const response = await apiRequest("/seller/profile", {
        method: "POST",
        token,
        body: shop,
      });
      setShop(response.data.shop);
      showToast("Shop profile saved.", { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setSaving(false);
    }
  };

  const uploadLogo = async () => {
    if (!logoFile) return;
    setUploading(true);
    setMessage("");
    try {
      const formData = new FormData();
      formData.append("file", logoFile);
      const response = await apiUpload("/seller/profile/logo", { formData, token });
      setShop(response.data.shop);
      setLogoFile(null);
      setFileInputKey((current) => current + 1);
      showToast(shop.logo_path ? "Shop logo replaced." : "Shop logo uploaded securely.", {
        type: "success",
      });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setUploading(false);
    }
  };

  const removeLogo = async () => {
    if (removingLogo) return;
    setRemovingLogo(true);
    try {
      const response = await apiRequest("/seller/profile/logo", {
        method: "DELETE",
        token,
      });
      setShop(response.data.shop);
      setLogoFile(null);
      setFileInputKey((current) => current + 1);
      setConfirmingLogoRemoval(false);
      showToast("Shop logo removed. Your shop initials are now shown instead.", {
        type: "success",
      });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setRemovingLogo(false);
    }
  };

  if (!shop) return <div className="route-loading">{message || "Loading shop profile…"}</div>;

  return (
    <section className="content-section page-section">
      <SellerNav path={path} navigate={navigate} />
      <div className="account-hero">
        <div>
          <span className="section-kicker">Shop identity</span>
          <h1 className="page-title">Shop profile</h1>
          <p>Keep the public contact and business information accurate.</p>
        </div>
        <StatusBadge status={shop.status} />
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      <section className="form-panel seller-logo-panel">
        <div className="shop-logo-preview">
          {shop.logo_path ? (
            <img
              src={mediaUrl("shop-logos", shop.logo_path)}
              alt={`${shop.name} logo`}
            />
          ) : (
            <span>{shop.name.slice(0, 1).toUpperCase()}</span>
          )}
        </div>
        <div>
          <span className="section-kicker">Public shop logo</span>
          <h2>Make your storefront recognizable</h2>
          <p>
            PNG, JPG or WebP up to 4 MB. Images are checked before storage.
            {shop.logo_path ? " Choose a new file to replace the current logo." : ""}
          </p>
          <div className="upload-row">
            <input
              key={fileInputKey}
              ref={logoInputRef}
              type="file"
              accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
              onChange={(event) => setLogoFile(event.target.files?.[0] ?? null)}
            />
            <button
              className="button button-primary"
              type="button"
              disabled={uploading}
              onClick={() => logoFile ? uploadLogo() : logoInputRef.current?.click()}
            >
              {uploading
                ? "Saving…"
                : logoFile
                  ? shop.logo_path ? "Save replacement" : "Upload selected logo"
                  : shop.logo_path ? "Choose replacement" : "Choose logo"}
            </button>
            {shop.logo_path && (
              <button
                className="button button-danger-outline"
                type="button"
                disabled={uploading || removingLogo}
                onClick={() => setConfirmingLogoRemoval(true)}
              >
                Remove logo
              </button>
            )}
          </div>
          {logoFile && <small className="selected-file-note">Selected: {logoFile.name}</small>}
        </div>
      </section>
      <form className="form-panel seller-profile-form" onSubmit={save}>
        <div className="form-grid">
          <label>
            Shop name
            <input
              value={shop.name}
              onChange={(event) => setShop({ ...shop, name: event.target.value })}
            />
          </label>
          <label>
            Public email
            <input
              type="email"
              value={shop.contact_email ?? ""}
              onChange={(event) =>
                setShop({ ...shop, contact_email: event.target.value })
              }
            />
          </label>
          <label>
            Public telephone
            <input
              value={shop.contact_phone ?? ""}
              onChange={(event) =>
                setShop({ ...shop, contact_phone: event.target.value })
              }
            />
          </label>
          <label>
            Marketplace rating
            <input
              value={`${shop.rating_average} / 5 (${shop.rating_count} reviews)`}
              disabled
            />
          </label>
          <label className="full-width">
            Address
            <textarea
              rows="3"
              value={shop.address_text ?? ""}
              onChange={(event) =>
                setShop({ ...shop, address_text: event.target.value })
              }
            />
          </label>
          <label className="full-width">
            Shop description
            <textarea
              rows="6"
              value={shop.description ?? ""}
              onChange={(event) =>
                setShop({ ...shop, description: event.target.value })
              }
            />
          </label>
        </div>
        <button className="button button-primary" disabled={saving}>
          {saving ? "Saving…" : "Save shop profile"}
        </button>
      </form>
      {confirmingLogoRemoval && (
        <Modal
          onClose={() => !removingLogo && setConfirmingLogoRemoval(false)}
          ariaLabel="Remove shop logo"
          closeOnBackdrop={!removingLogo}
        >
          <span className="section-kicker">Storefront identity</span>
          <h2>Remove this shop logo?</h2>
          <p>Your shop initials will be displayed until you upload another logo.</p>
          <div className="modal-actions">
            <button
              className="button button-ghost"
              type="button"
              disabled={removingLogo}
              onClick={() => setConfirmingLogoRemoval(false)}
            >
              Keep logo
            </button>
            <button
              className="button button-danger"
              type="button"
              disabled={removingLogo}
              onClick={removeLogo}
            >
              {removingLogo ? "Removing…" : "Remove logo"}
            </button>
          </div>
        </Modal>
      )}
    </section>
  );
}
