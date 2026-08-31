import { useEffect, useMemo, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import { useAuth } from "../contexts/AuthContext";
import { useToast } from "../contexts/ToastContext";

export default function BuyerCartPage({ navigate, path = "/cart" }) {
  const { token } = useAuth();
  const { showToast } = useToast();
  const [cart, setCart] = useState({
    items: [],
    setups: [],
    summary: { quantity: 0, subtotal: "0.00", ready_for_checkout: false },
  });
  const [message, setMessage] = useState("");
  const [pendingItem, setPendingItem] = useState("");

  const load = () =>
    apiRequest("/cart", { token })
      .then((response) => setCart(response.data.cart))
      .catch((error) => setMessage(error.message));

  useEffect(() => {
    load();
  }, [token]);

  const shops = useMemo(() => {
    const groups = new Map();
    cart.items.forEach((item) => {
      if (!groups.has(item.shop_id)) {
        groups.set(item.shop_id, { id: item.shop_id, name: item.shop_name, items: [] });
      }
      groups.get(item.shop_id).items.push(item);
    });
    return [...groups.values()];
  }, [cart]);

  const updateQuantity = async (item, quantity) => {
    const actionKey = `quantity-${item.id}`;
    if (pendingItem) return;
    setPendingItem(actionKey);
    try {
      const response = await apiRequest(`/cart/items/${item.id}`, {
        method: "PATCH",
        token,
        body: { quantity: Number(quantity) },
      });
      setCart(response.data.cart);
      setMessage("");
      showToast(`${item.product_name} quantity updated.`, { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setPendingItem("");
    }
  };

  const removeItem = async (item) => {
    const actionKey = `remove-${item.id}`;
    if (pendingItem) return;
    setPendingItem(actionKey);
    try {
      const response = await apiRequest(`/cart/items/${item.id}`, {
        method: "DELETE",
        token,
      });
      setCart(response.data.cart);
      showToast(`${item.product_name} was removed from your cart.`, { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setPendingItem("");
    }
  };

  const restoreSetup = async (setup) => {
    const actionKey = `restore-setup-${setup.public_id}`;
    if (pendingItem) return;
    setPendingItem(actionKey);
    try {
      const response = await apiRequest(`/cart/setups/${setup.public_id}/restore`, {
        method: "POST",
        token,
      });
      setCart(response.data.cart);
      showToast(`${setup.name} was restored to your cart.`, { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 7000 });
    } finally {
      setPendingItem("");
    }
  };

  const releaseSetup = async (setup) => {
    const actionKey = `release-setup-${setup.public_id}`;
    if (pendingItem) return;
    setPendingItem(actionKey);
    try {
      const response = await apiRequest(`/cart/setups/${setup.public_id}`, {
        method: "DELETE",
        token,
      });
      setCart(response.data.cart);
      showToast("Saved setup requirement removed. Your current cart products were kept.", {
        type: "success",
      });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 7000 });
    } finally {
      setPendingItem("");
    }
  };

  return (
    <section className="content-section page-section">
      <BuyerNav path={path} navigate={navigate} />
      <div className="section-heading buyer-page-heading">
        <div>
          <span className="section-kicker">One cart, approved sellers</span>
          <h1 className="page-title">Shopping cart</h1>
          <p>Hexbay separates each shop during checkout while keeping one order total.</p>
        </div>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      {cart.items.length === 0 ? (
        <div className="empty-marketplace">
          <h3>Your cart is empty</h3>
          <p>Compare approved seller offers and add the one you prefer.</p>
          <button className="button button-primary" onClick={() => navigate("/products")}>
            Explore technology
          </button>
        </div>
      ) : (
        <>
          {cart.setups?.length > 0 && (
            <section className="cart-setup-identities" aria-label="HexBot setups in this cart">
              {cart.setups.map((setup) => (
                <article className="cart-setup-identity" key={setup.public_id}>
                  <div className="cart-setup-title">
                    <span className="section-kicker">Saved HexBot setup</span>
                    <h2>{setup.name}</h2>
                    <p>
                      Build {setup.build_rank} · {setup.item_count} products · {setup.shop_count} {setup.shop_count === 1 ? "seller" : "sellers"}
                    </p>
                  </div>
                  <div className="cart-setup-facts">
                    <span>Saved total<strong>LKR {Number(setup.selected_total_lkr).toLocaleString()}</strong></span>
                    <span>Target<strong>LKR {Number(setup.target_budget_lkr).toLocaleString()}</strong></span>
                    <span>Compatibility<strong className="stock-ready">{setup.compatibility?.status || "Recorded"}</strong></span>
                  </div>
                  <div className="cart-setup-components">
                    {setup.items.map((item) => (
                      <span key={item.id} title={`${item.product_name} from ${item.shop_name}`}>
                        {item.component_code.replaceAll("_", " ")}
                      </span>
                    ))}
                  </div>
                  {!setup.is_complete_in_cart && (
                    <div className="cart-setup-warning">
                      <p>
                        {setup.item_count - setup.present_item_count} of the {setup.item_count} saved setup products {setup.item_count - setup.present_item_count === 1 ? "is" : "are"} missing from this cart. Checkout is paused so an incomplete PC is not purchased accidentally.
                      </p>
                      <div className="cart-setup-actions">
                        <button
                          type="button"
                          className="button button-primary"
                          disabled={Boolean(pendingItem)}
                          onClick={() => restoreSetup(setup)}
                        >
                          {pendingItem === `restore-setup-${setup.public_id}` ? "Restoring…" : "Restore missing products"}
                        </button>
                        <button
                          type="button"
                          className="button button-ghost"
                          disabled={Boolean(pendingItem)}
                          onClick={() => releaseSetup(setup)}
                        >
                          {pendingItem === `release-setup-${setup.public_id}` ? "Removing setup…" : "Keep items, remove setup"}
                        </button>
                      </div>
                    </div>
                  )}
                  <small>Setup ID {setup.public_id}</small>
                </article>
              ))}
            </section>
          )}
          <div className="cart-page-layout">
            <div className="cart-shop-list">
            {shops.map((shop) => (
              <section className="cart-shop-group" key={shop.id}>
                <div className="cart-shop-heading">
                  <span>Seller</span>
                  <button onClick={() => navigate(`/shops/${shop.id}`)}>
                    {shop.name}
                  </button>
                </div>
                {shop.items.map((item) => (
                  <article className="cart-line-item" key={item.id}>
                    <button
                      className="buyer-line-image"
                      onClick={() => navigate(`/products/${item.product_id}`)}
                    >
                      {item.image_filename ? (
                        <img
                          src={mediaUrl("product-images", item.image_filename)}
                          alt={item.product_name}
                        />
                      ) : (
                        <span>{item.brand_name.slice(0, 1)}</span>
                      )}
                    </button>
                    <div>
                      <h2>{item.product_name}</h2>
                      <p>{item.brand_name} · {item.model}</p>
                      <span className={item.is_available ? "stock-ready" : "stock-empty"}>
                        {item.is_available
                          ? `${item.available_quantity} available`
                          : "Needs attention before checkout"}
                      </span>
                    </div>
                    <label className="cart-quantity">
                      Quantity
                      <select
                        value={item.quantity}
                        disabled={Boolean(pendingItem)}
                        onChange={(event) => updateQuantity(item, event.target.value)}
                      >
                        {Array.from(
                          { length: Math.min(10, Number(item.available_quantity)) },
                          (_, index) => index + 1,
                        ).map((quantity) => (
                          <option value={quantity} key={quantity}>{quantity}</option>
                        ))}
                      </select>
                    </label>
                    <div className="cart-line-total">
                      <strong>LKR {Number(item.line_total).toLocaleString()}</strong>
                      <small>LKR {Number(item.price).toLocaleString()} each</small>
                      <button
                        className="text-link danger-link"
                        disabled={Boolean(pendingItem)}
                        onClick={() => removeItem(item)}
                      >
                        {pendingItem === `remove-${item.id}` ? "Removing…" : "Remove"}
                      </button>
                    </div>
                  </article>
                ))}
              </section>
            ))}
            </div>
            <aside className="checkout-summary-card">
            <span className="section-kicker">Order estimate</span>
            <h2>Summary</h2>
            <div>
              <span>{cart.summary.quantity} items</span>
              <strong>LKR {Number(cart.summary.subtotal).toLocaleString()}</strong>
            </div>
            <p>
              Prices and stock are checked again securely when you place the order.
            </p>
            <button
              className="button button-primary full-button"
              disabled={!cart.summary.ready_for_checkout}
              onClick={() => navigate("/checkout")}
            >
              Continue to checkout
            </button>
            <button className="text-link" onClick={() => navigate("/products")}>
              Continue shopping
            </button>
            </aside>
          </div>
        </>
      )}
    </section>
  );
}
