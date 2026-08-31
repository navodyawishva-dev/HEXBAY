import { useEffect, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import BuyerNav from "../components/BuyerNav";
import { useAuth } from "../contexts/AuthContext";
import { useToast } from "../contexts/ToastContext";

export default function BuyerWishlistPage({ navigate, path = "/wishlist" }) {
  const { token } = useAuth();
  const { showToast } = useToast();
  const [wishlist, setWishlist] = useState({ items: [] });
  const [message, setMessage] = useState("");
  const [pendingItem, setPendingItem] = useState("");

  const addToCart = async (item) => {
    if (pendingItem) return;
    setPendingItem(`cart-${item.listing_id}`);
    try {
      await apiRequest("/cart/items", {
        method: "POST",
        token,
        body: { listing_id: item.listing_id, quantity: 1 },
      });
      showToast(`${item.product_name} was added to your cart.`, {
        type: "success",
        actionLabel: "View",
        onAction: () => navigate("/cart"),
      });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setPendingItem("");
    }
  };

  const removeFromWishlist = async (item) => {
    if (pendingItem) return;
    setPendingItem(`remove-${item.listing_id}`);
    try {
      const response = await apiRequest(`/wishlist/items/${item.listing_id}`, {
        method: "DELETE",
        token,
      });
      setWishlist(response.data.wishlist);
      showToast(`${item.product_name} was removed from your wishlist.`, { type: "success" });
    } catch (error) {
      showToast(error.message, { type: "error", duration: 6000 });
    } finally {
      setPendingItem("");
    }
  };

  const load = () =>
    apiRequest("/wishlist/items", { token })
      .then((response) => setWishlist(response.data.wishlist))
      .catch((error) => setMessage(error.message));

  useEffect(() => {
    load();
  }, [token]);

  return (
    <section className="content-section page-section">
      <BuyerNav path={path} navigate={navigate} />
      <div className="section-heading buyer-page-heading">
        <div>
          <span className="section-kicker">Saved for later</span>
          <h1 className="page-title">Your wishlist</h1>
          <p>Wishlist entries remember the exact seller offer you selected.</p>
        </div>
        <strong>{wishlist.items.length} saved</strong>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      {wishlist.items.length === 0 ? (
        <div className="empty-marketplace">
          <h3>Your wishlist is ready</h3>
          <p>Compare a product and save an offer that interests you.</p>
          <button className="button button-primary" onClick={() => navigate("/products")}>
            Browse products
          </button>
        </div>
      ) : (
        <div className="wishlist-list">
          {wishlist.items.map((item) => (
            <article className="buyer-line-card" key={item.listing_id}>
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
                <span className="product-category">{item.category_name}</span>
                <h2>{item.product_name}</h2>
                <p>{item.brand_name} · {item.model}</p>
                <small>Sold by {item.shop_name}</small>
              </div>
              <div className="buyer-line-price">
                <strong>LKR {Number(item.price).toLocaleString()}</strong>
                <span className={Number(item.available_quantity) ? "stock-ready" : "stock-empty"}>
                  {Number(item.available_quantity)
                    ? `${item.available_quantity} available`
                    : "Out of stock"}
                </span>
              </div>
              <div className="buyer-line-actions">
                <button
                  className="button button-primary"
                  disabled={!Number(item.available_quantity) || Boolean(pendingItem)}
                  onClick={() => addToCart(item)}
                >
                  {pendingItem === `cart-${item.listing_id}` ? "Adding…" : "Add to cart"}
                </button>
                <button
                  className="text-link danger-link"
                  disabled={Boolean(pendingItem)}
                  onClick={() => removeFromWishlist(item)}
                >
                  {pendingItem === `remove-${item.listing_id}` ? "Removing…" : "Remove"}
                </button>
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
