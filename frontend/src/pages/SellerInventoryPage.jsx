import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import SellerNav from "../components/SellerNav";
import StatusBadge from "../components/StatusBadge";
import Modal from "../components/Modal";
import { useToast } from "../contexts/ToastContext";

export default function SellerInventoryPage({ navigate, path }) {
  const { token } = useAuth();
  const { showToast } = useToast();
  const [items, setItems] = useState([]);
  const [adjustment, setAdjustment] = useState(null);
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  const load = () =>
    apiRequest("/seller/inventory", { token }).then((response) =>
      setItems(response.data.inventory),
    );

  useEffect(() => {
    load().catch((error) => setMessage(error.message));
  }, [token]);

  const saveAdjustment = async (event) => {
    event.preventDefault();
    if (saving) return;
    const quantity = Number(adjustment.quantity);
    const quantityDelta = adjustment.mode === "set"
      ? quantity - Number(adjustment.quantity_on_hand)
      : adjustment.mode === "remove" ? -quantity : quantity;
    setSaving(true);
    try {
      const response = await apiRequest(`/seller/inventory/${adjustment.listing_id}/adjust`, {
        method: "POST",
        token,
        body: {
          quantity_delta: quantityDelta,
          reason: adjustment.reason,
        },
      });
      setItems((current) => current.map((item) => (
        Number(item.listing_id) === Number(adjustment.listing_id)
          ? response.data.inventory
          : item
      )));
      setAdjustment(null);
      const resultMessage = adjustment.mode === "set"
        ? `${adjustment.product_name}: on-hand stock set to ${quantity}.`
        : `${adjustment.product_name}: ${adjustment.mode === "add" ? "added" : "removed"} ${quantity} ${quantity === 1 ? "unit" : "units"}.`;
      showToast(resultMessage, { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setSaving(false);
    }
  };

  const adjustmentQuantity = Number(adjustment?.quantity || 0);
  const projectedStock = adjustment
    ? adjustment.mode === "set"
      ? adjustmentQuantity
      : Number(adjustment.quantity_on_hand)
        + (adjustment.mode === "remove" ? -adjustmentQuantity : adjustmentQuantity)
    : 0;
  const projectedDelta = adjustment
    ? projectedStock - Number(adjustment.quantity_on_hand)
    : 0;
  const adjustmentIsValid = Boolean(
    adjustment
      && Number.isInteger(adjustmentQuantity)
      && (adjustment.mode === "set" ? adjustmentQuantity >= 0 : adjustmentQuantity > 0)
      && adjustmentQuantity <= 1000000
      && projectedDelta !== 0
      && adjustment.reason.trim().length >= 5
      && projectedStock >= Number(adjustment.quantity_reserved),
  );

  return (
    <section className="content-section page-section">
      <SellerNav path={path} navigate={navigate} />
      <div className="account-hero">
        <div>
          <span className="section-kicker">Stock control</span>
          <h1 className="page-title">Inventory</h1>
          <p>Every manual change is recorded as an inventory movement.</p>
        </div>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      <section className="admin-panel">
        {items.length === 0 ? (
          <div className="compact-empty">Create a product before managing stock.</div>
        ) : (
          <div className="admin-table-wrap">
            <table className="admin-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>On hand</th>
                  <th>Reserved</th>
                  <th>Available to sell</th>
                  <th>Listing</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => {
                  const availableToSell = Math.max(
                    0,
                    Number(item.quantity_on_hand) - Number(item.quantity_reserved),
                  );
                  const lowStock = availableToSell <= Number(item.low_stock_threshold);
                  return (
                    <tr key={item.inventory_id}>
                      <td>
                        <strong>{item.product_name}</strong>
                        <small>
                          {item.model} · {item.sku}
                        </small>
                      </td>
                      <td>
                        <strong>{item.quantity_on_hand}</strong>
                        {lowStock && <small className="low-stock-text">Low stock</small>}
                      </td>
                      <td>{item.quantity_reserved}</td>
                      <td>
                        <strong>{availableToSell}</strong>
                        {lowStock && <small className="low-stock-text">Low stock</small>}
                      </td>
                      <td>
                        <StatusBadge status={item.listing_status} />
                      </td>
                      <td>
                        <button
                          className="table-action"
                          onClick={() =>
                            setAdjustment({
                              ...item,
                              mode: "add",
                              quantity: "",
                              reason: "",
                            })
                          }
                        >
                          Adjust
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {adjustment && (
        <Modal onClose={() => setAdjustment(null)} ariaLabel="Adjust inventory">
          <form className="inventory-adjustment-form" onSubmit={saveAdjustment}>
            <span className="section-kicker">Inventory movement</span>
            <h2>{adjustment.product_name}</h2>
            <div className="inventory-stock-summary">
              <span>On hand<strong>{adjustment.quantity_on_hand}</strong></span>
              <span>Reserved<strong>{adjustment.quantity_reserved}</strong></span>
              <span>Available to sell<strong>{Math.max(0, Number(adjustment.quantity_on_hand) - Number(adjustment.quantity_reserved))}</strong></span>
            </div>
            <fieldset className="inventory-adjustment-modes">
              <legend>What changed?</legend>
              <button
                type="button"
                className={adjustment.mode === "add" ? "active" : ""}
                onClick={() => setAdjustment({ ...adjustment, mode: "add" })}
              >
                Add stock
              </button>
              <button
                type="button"
                className={adjustment.mode === "remove" ? "active" : ""}
                onClick={() => setAdjustment({ ...adjustment, mode: "remove" })}
              >
                Remove stock
              </button>
              <button
                type="button"
                className={adjustment.mode === "set" ? "active" : ""}
                onClick={() => setAdjustment({ ...adjustment, mode: "set" })}
              >
                Set exact count
              </button>
            </fieldset>
            <label>
              {adjustment.mode === "set" ? "Exact on-hand quantity" : `Number of units to ${adjustment.mode}`}
              <input
                type="number"
                min={adjustment.mode === "set" ? "0" : "1"}
                max="1000000"
                step="1"
                required
                value={adjustment.quantity}
                placeholder={adjustment.mode === "set" ? "Enter the physical count" : "Enter a positive quantity"}
                onChange={(event) =>
                  setAdjustment({
                    ...adjustment,
                    quantity: event.target.value,
                  })
                }
              />
              <small>
                {adjustment.mode === "add"
                  ? "Use this for a new shipment or a higher physical count."
                  : adjustment.mode === "remove"
                    ? "Use this for damaged, missing, or incorrectly counted stock."
                    : "Use this after a physical count. Enter 0 to mark the product out of stock."}
              </small>
              {adjustment.quantity !== "" && projectedDelta === 0 && (
                <small className="low-stock-text">Enter a quantity that changes the current stock.</small>
              )}
            </label>
            <label>
              Reason
              <textarea
                rows="4"
                minLength="5"
                maxLength="500"
                required
                value={adjustment.reason}
                placeholder="New shipment, damaged stock, manual count correction…"
                onChange={(event) =>
                  setAdjustment({ ...adjustment, reason: event.target.value })
                }
              />
              <small>Required for the inventory audit history (5–500 characters).</small>
            </label>
            {adjustment.quantity !== "" && Number.isInteger(adjustmentQuantity) && (
              <div className={`inventory-projection ${projectedStock < Number(adjustment.quantity_reserved) ? "invalid" : ""}`}>
                <span>New on-hand quantity</span>
                <strong>{projectedStock}</strong>
                {projectedStock < Number(adjustment.quantity_reserved) && (
                  <small>You cannot remove units reserved for existing orders.</small>
                )}
              </div>
            )}
            <div className="modal-actions">
              <button
                className="button button-ghost"
                type="button"
                disabled={saving}
                onClick={() => setAdjustment(null)}
              >
                Cancel
              </button>
              <button className="button button-primary" disabled={!adjustmentIsValid || saving}>
                {saving ? "Recording…" : adjustment.mode === "set" ? "Save exact count" : `Record ${adjustment.mode}`}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </section>
  );
}
