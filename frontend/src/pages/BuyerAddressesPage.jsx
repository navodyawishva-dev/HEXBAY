import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import { useAuth } from "../contexts/AuthContext";
import { useToast } from "../contexts/ToastContext";

const emptyAddress = {
  label: "Home",
  recipient_name: "",
  phone: "",
  address_line_1: "",
  address_line_2: "",
  city: "",
  district: "",
  postal_code: "",
  country_code: "LK",
  is_default: false,
};

export default function BuyerAddressesPage({ navigate, path = "/addresses" }) {
  const { token, user } = useAuth();
  const { showToast } = useToast();
  const [addresses, setAddresses] = useState([]);
  const [form, setForm] = useState({
    ...emptyAddress,
    recipient_name: [user?.first_name, user?.last_name].filter(Boolean).join(" "),
    phone: user?.phone || "",
  });
  const [editingId, setEditingId] = useState(null);
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  const load = () =>
    apiRequest("/customers/me/addresses", { token })
      .then((response) => setAddresses(response.data.addresses))
      .catch((error) => setMessage(error.message));

  useEffect(() => {
    load();
  }, [token]);

  const updateField = (field, value) => setForm({ ...form, [field]: value });

  const reset = () => {
    setEditingId(null);
    setForm({
      ...emptyAddress,
      recipient_name: [user?.first_name, user?.last_name].filter(Boolean).join(" "),
      phone: user?.phone || "",
    });
  };

  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setMessage("");
    try {
      await apiRequest(
        editingId
          ? `/customers/me/addresses/${editingId}`
          : "/customers/me/addresses",
        {
          method: editingId ? "PATCH" : "POST",
          token,
          body: form,
        },
      );
      showToast(editingId ? "Address updated." : "Address saved.", { type: "success" });
      reset();
      await load();
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setSaving(false);
    }
  };

  const removeAddress = async (address) => {
    if (deletingId !== null) return;
    setDeletingId(address.id);
    try {
      await apiRequest(`/customers/me/addresses/${address.id}`, {
        method: "DELETE",
        token,
      });
      if (Number(editingId) === Number(address.id)) reset();
      await load();
      showToast(`${address.label} address removed.`, { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <section className="content-section page-section">
      <BuyerNav path={path} navigate={navigate} />
      <div className="section-heading buyer-page-heading">
        <div>
          <span className="section-kicker">Delivery details</span>
          <h1 className="page-title">Saved addresses</h1>
          <p>Checkout uses a protected snapshot, so later edits do not change old orders.</p>
        </div>
      </div>
      {message && (
        <div className={`alert ${message.includes("saved") || message.includes("updated") ? "alert-success" : "alert-error"}`}>
          {message}
        </div>
      )}
      <div className="address-page-grid">
        <section className="admin-panel">
          <h2>{editingId ? "Edit address" : "Add delivery address"}</h2>
          <form className="form-grid buyer-address-form" onSubmit={save}>
            <label>
              Label
              <input
                value={form.label}
                onChange={(event) => updateField("label", event.target.value)}
                required
              />
            </label>
            <label>
              Recipient
              <input
                value={form.recipient_name}
                onChange={(event) => updateField("recipient_name", event.target.value)}
                required
              />
            </label>
            <label>
              Telephone
              <input
                value={form.phone}
                onChange={(event) => updateField("phone", event.target.value)}
                required
              />
            </label>
            <label>
              City
              <input
                value={form.city}
                onChange={(event) => updateField("city", event.target.value)}
                required
              />
            </label>
            <label className="full-width">
              Address line 1
              <input
                value={form.address_line_1}
                onChange={(event) => updateField("address_line_1", event.target.value)}
                required
              />
            </label>
            <label className="full-width">
              Address line 2 (optional)
              <input
                value={form.address_line_2}
                onChange={(event) => updateField("address_line_2", event.target.value)}
              />
            </label>
            <label>
              District
              <input
                value={form.district}
                onChange={(event) => updateField("district", event.target.value)}
                required
              />
            </label>
            <label>
              Postal code
              <input
                value={form.postal_code}
                onChange={(event) => updateField("postal_code", event.target.value)}
              />
            </label>
            <label className="check-row full-width">
              <input
                type="checkbox"
                checked={form.is_default}
                onChange={(event) => updateField("is_default", event.target.checked)}
              />
              Use as my default address
            </label>
            <div className="panel-actions full-width">
              <button className="button button-primary" disabled={saving}>
                {saving ? "Saving…" : editingId ? "Update address" : "Save address"}
              </button>
              {editingId && (
                <button type="button" className="button button-ghost" onClick={reset}>
                  Cancel
                </button>
              )}
            </div>
          </form>
        </section>
        <section className="address-card-list">
          {addresses.length === 0 ? (
            <div className="empty-marketplace">
              <h3>No delivery address yet</h3>
              <p>Add one now so checkout takes only a moment.</p>
            </div>
          ) : (
            addresses.map((address) => (
              <article className="buyer-address-card" key={address.id}>
                <div className="panel-title-row">
                  <strong>{address.label}</strong>
                  {address.is_default && <span className="default-pill">Default</span>}
                </div>
                <h3>{address.recipient_name}</h3>
                <p>
                  {address.address_line_1}
                  {address.address_line_2 ? `, ${address.address_line_2}` : ""}
                  <br />
                  {address.city}, {address.district} {address.postal_code}
                </p>
                <small>{address.phone}</small>
                <div className="panel-actions">
                  <button
                    className="button button-ghost"
                    disabled={deletingId !== null}
                    onClick={() => {
                      setEditingId(address.id);
                      setForm({
                        label: address.label,
                        recipient_name: address.recipient_name,
                        phone: address.phone,
                        address_line_1: address.address_line_1,
                        address_line_2: address.address_line_2 || "",
                        city: address.city,
                        district: address.district,
                        postal_code: address.postal_code || "",
                        country_code: address.country_code,
                        is_default: Boolean(address.is_default),
                      });
                      window.scrollTo({ top: 0, behavior: "smooth" });
                    }}
                  >
                    Edit
                  </button>
                  <button
                    className="text-link danger-link"
                    disabled={deletingId !== null}
                    onClick={() => removeAddress(address)}
                  >
                    {deletingId === address.id ? "Removing…" : "Remove"}
                  </button>
                </div>
              </article>
            ))
          )}
        </section>
      </div>
    </section>
  );
}
