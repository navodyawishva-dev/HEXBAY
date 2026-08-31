import { useEffect, useMemo, useState } from "react";
import { apiRequest } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import { useAuth } from "../contexts/AuthContext";

const lkr = (value) => Number(value || 0).toLocaleString("en-LK", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

const roleLabel = (value) => String(value || "product")
  .replaceAll("_", " ")
  .replace(/\b\w/g, (letter) => letter.toUpperCase());

export default function BuyerCheckoutPage({ navigate }) {
  const { token } = useAuth();
  const [cart, setCart] = useState({
    items: [],
    setups: [],
    summary: { quantity: 0, subtotal: "0.00", ready_for_checkout: false },
  });
  const [addresses, setAddresses] = useState([]);
  const [addressId, setAddressId] = useState("");
  const [paymentAcknowledged, setPaymentAcknowledged] = useState(false);
  const [message, setMessage] = useState("");
  const [placing, setPlacing] = useState(false);

  useEffect(() => {
    Promise.all([
      apiRequest("/cart", { token }),
      apiRequest("/customers/me/addresses", { token }),
    ])
      .then(([cartResponse, addressResponse]) => {
        const nextAddresses = addressResponse.data.addresses;
        setCart(cartResponse.data.cart);
        setAddresses(nextAddresses);
        setAddressId(String(nextAddresses.find((address) => address.is_default)?.id ?? nextAddresses[0]?.id ?? ""));
      })
      .catch((error) => setMessage(error.message));
  }, [token]);

  const shops = useMemo(() => {
    const grouped = new Map();
    cart.items.forEach((item) => {
      if (!grouped.has(item.shop_id)) {
        grouped.set(item.shop_id, { id: item.shop_id, name: item.shop_name, items: [], subtotal: 0 });
      }
      const shop = grouped.get(item.shop_id);
      shop.items.push(item);
      shop.subtotal += Number(item.line_total);
    });
    return [...grouped.values()];
  }, [cart.items]);

  const setupSummaries = useMemo(() => (cart.setups || []).map((setup) => {
    const currentTotal = setup.items.reduce((total, setupItem) => {
      const cartItem = cart.items.find((item) => Number(item.listing_id) === Number(setupItem.listing_id));
      return total + (cartItem ? Number(cartItem.price) * Number(setupItem.quantity) : 0);
    }, 0);
    return { ...setup, currentTotal, priceDifference: currentTotal - Number(setup.selected_total_lkr) };
  }), [cart.items, cart.setups]);

  const setupsComplete = setupSummaries.every((setup) => setup.is_complete_in_cart);
  const canPlace = Boolean(addressId && paymentAcknowledged && cart.summary.ready_for_checkout && setupsComplete && !placing);

  const placeOrder = async () => {
    setPlacing(true);
    setMessage("");
    try {
      const response = await apiRequest("/orders", {
        method: "POST",
        token,
        body: {
          address_id: Number(addressId),
          payment_method: "card_simulation",
          simulated_payment_acknowledged: paymentAcknowledged,
          expected_total_lkr: cart.summary.subtotal,
          setup_public_ids: setupSummaries.map((setup) => setup.public_id),
        },
      });
      navigate(`/orders/${response.data.order.id}`);
    } catch (error) {
      setMessage(`${error.message} Refresh this checkout to review the latest details.`);
    } finally {
      setPlacing(false);
    }
  };

  return (
    <section className="content-section page-section checkout-experience">
      <BuyerNav path="/cart" navigate={navigate} />
      <div className="section-heading buyer-page-heading">
        <div>
          <span className="section-kicker">Setup checkout</span>
          <h1 className="page-title">Review the complete order</h1>
          <p>Your HexBot setup stays named and traceable while each approved seller fulfils its own products.</p>
        </div>
        <strong>{shops.length} {shops.length === 1 ? "seller delivery" : "seller deliveries"}</strong>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      {cart.items.length === 0 ? (
        <div className="empty-marketplace">
          <h3>There is nothing to check out</h3>
          <p>Return to X Board or the marketplace to choose products.</p>
          <button className="button button-primary" onClick={() => navigate("/x-board")}>Open X Board</button>
        </div>
      ) : (
        <div className="checkout-page-grid checkout-detail-grid">
          <div className="checkout-main-column">
            {setupSummaries.length > 0 && (
              <section className="admin-panel checkout-section-card">
                <div className="panel-title-row">
                  <div><span className="section-kicker">1 · HexBot identity</span><h2>Complete setup summary</h2></div>
                  <button className="text-link" onClick={() => navigate("/x-board")}>Return to X Board</button>
                </div>
                <div className="checkout-setup-list">
                  {setupSummaries.map((setup) => (
                    <article className="checkout-setup-card" key={setup.public_id}>
                      <div className="checkout-setup-heading">
                        <div><h3>{setup.name}</h3><small>Setup ID {setup.public_id}</small></div>
                        <span className={setup.is_complete_in_cart ? "checkout-ready-pill" : "checkout-blocked-pill"}>
                          {setup.is_complete_in_cart ? "Complete" : "Incomplete"}
                        </span>
                      </div>
                      <div className="checkout-setup-metrics">
                        <span>Current total<strong>LKR {lkr(setup.currentTotal)}</strong></span>
                        <span>HexBot estimate<strong>LKR {lkr(setup.selected_total_lkr)}</strong></span>
                        <span>Compatibility<strong>{roleLabel(setup.compatibility?.status || "Recorded")}</strong></span>
                      </div>
                      {setup.priceDifference !== 0 && (
                        <p className="checkout-price-note">Live seller prices are LKR {lkr(Math.abs(setup.priceDifference))} {setup.priceDifference > 0 ? "above" : "below"} the saved HexBot estimate.</p>
                      )}
                      <div className="checkout-component-grid">
                        {setup.items.map((item) => (
                          <div key={item.id}>
                            <span>{roleLabel(item.component_code)}</span><strong>{item.product_name}</strong><small>{item.shop_name}</small>
                          </div>
                        ))}
                      </div>
                    </article>
                  ))}
                </div>
              </section>
            )}

            <section className="admin-panel checkout-section-card">
              <div className="panel-title-row">
                <div><span className="section-kicker">2 · Fulfilment preview</span><h2>Products grouped by seller</h2></div>
                <span className="checkout-muted-note">Delivery fees are not included in this prototype.</span>
              </div>
              <div className="checkout-seller-list">
                {shops.map((shop, index) => (
                  <article className="checkout-seller-card" key={shop.id}>
                    <div className="checkout-seller-heading">
                      <div><span>Delivery {index + 1}</span><button onClick={() => navigate(`/shops/${shop.id}`)}>{shop.name}</button></div>
                      <strong>LKR {lkr(shop.subtotal)}</strong>
                    </div>
                    {shop.items.map((item) => (
                      <div className="checkout-seller-line" key={item.id}>
                        <div><strong>{item.product_name}</strong><small>{item.brand_name} · Quantity {item.quantity}</small></div>
                        <span>LKR {lkr(item.line_total)}</span>
                      </div>
                    ))}
                    <small className="checkout-delivery-note">This seller receives and updates one separate fulfilment order after placement.</small>
                  </article>
                ))}
              </div>
            </section>

            <section className="admin-panel checkout-section-card">
              <div className="panel-title-row">
                <div><span className="section-kicker">3 · Destination</span><h2>Delivery address</h2></div>
                <button className="text-link" onClick={() => navigate("/addresses")}>Manage addresses</button>
              </div>
              {addresses.length === 0 ? (
                <div className="compact-empty"><p>Add a delivery address before placing the order.</p><button className="button button-primary" onClick={() => navigate("/addresses")}>Add address</button></div>
              ) : (
                <div className="checkout-address-list">
                  {addresses.map((address) => (
                    <label className={String(address.id) === addressId ? "selected" : ""} key={address.id}>
                      <input type="radio" name="delivery-address" value={address.id} checked={String(address.id) === addressId} onChange={(event) => setAddressId(event.target.value)} />
                      <div><strong>{address.label} · {address.recipient_name}</strong><span>{address.address_line_1}, {address.city}, {address.district}</span><small>{address.phone}</small></div>
                    </label>
                  ))}
                </div>
              )}
            </section>

            <section className="admin-panel checkout-section-card">
              <div className="panel-title-row">
                <div><span className="section-kicker">4 · Card payment</span><h2>Card-only checkout</h2></div>
                <span className="checkout-demo-pill">No real charge</span>
              </div>
              <p className="checkout-section-copy">Hexbay accepts card payments only. This academic version simulates secure card authorization and never asks for or stores card numbers.</p>
              <div className="checkout-card-only-method" aria-label="Selected payment method">
                <span className="checkout-card-symbol" aria-hidden="true">CARD</span>
                <span>
                  <strong>Card payment simulation</strong>
                  <small>Authorization is simulated. Cash on delivery and bank transfer are not supported.</small>
                </span>
                <span className="checkout-selected-pill">Selected</span>
              </div>
              <label className="checkout-payment-acknowledgement">
                <input type="checkbox" checked={paymentAcknowledged} onChange={(event) => setPaymentAcknowledged(event.target.checked)} />
                <span>I understand card authorization is simulated and no real payment will be collected.</span>
              </label>
            </section>
          </div>

          <aside className="checkout-summary-card checkout-final-card checkout-verification-card">
            <span className="section-kicker">Final verification</span>
            <h2>Ready to place?</h2>
            <div><span>{cart.summary.quantity} products</span><strong>LKR {lkr(cart.summary.subtotal)}</strong></div>
            <div className="checkout-order-facts"><span>{setupSummaries.length} saved {setupSummaries.length === 1 ? "setup" : "setups"}</span><span>{shops.length} seller {shops.length === 1 ? "delivery" : "deliveries"}</span></div>
            <ul className="checkout-gate-list">
              <li className={cart.summary.ready_for_checkout ? "ready" : "blocked"}>All products are currently available</li>
              <li className={setupsComplete ? "ready" : "blocked"}>Every saved setup is complete</li>
              <li className={addressId ? "ready" : "blocked"}>Delivery address selected</li>
              <li className={paymentAcknowledged ? "ready" : "blocked"}>Card simulation notice accepted</li>
            </ul>
            <p>When you place the order, Hexbay locks stock, verifies this exact total, and creates one fulfilment order per seller.</p>
            <button className="button button-primary full-button" disabled={!canPlace} onClick={placeOrder}>{placing ? "Authorizing and placing…" : "Authorize card and place order"}</button>
            <button className="text-link" onClick={() => navigate("/cart")}>Back to cart</button>
            <small>No card details or real funds are processed in this academic version.</small>
          </aside>
        </div>
      )}
    </section>
  );
}
