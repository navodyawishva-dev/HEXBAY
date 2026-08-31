import { useEffect, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import MarketplaceProductCard from "../components/MarketplaceProductCard";

export default function ShopDetailPage({ shopId, navigate }) {
  const [shop, setShop] = useState(null);
  const [message, setMessage] = useState("");

  useEffect(() => {
    apiRequest(`/shops/${shopId}`)
      .then((response) => setShop(response.data.shop))
      .catch((error) => setMessage(error.message));
  }, [shopId]);

  if (!shop) {
    return <div className="route-loading">{message || "Loading shop…"}</div>;
  }

  return (
    <section className="content-section page-section">
      <button className="text-link" onClick={() => navigate("/products")}>
        ← Back to marketplace
      </button>
      <div className="shop-public-hero">
        <div className="shop-public-logo">
          {shop.logo_path ? (
            <img
              src={mediaUrl("shop-logos", shop.logo_path)}
              alt={`${shop.name} logo`}
            />
          ) : (
            <span>{shop.name.slice(0, 1)}</span>
          )}
        </div>
        <div>
          <span className="section-kicker">Approved Hexbay shop</span>
          <h1>{shop.name}</h1>
          <p>{shop.description || "Local technology seller on Hexbay."}</p>
          <div className="shop-public-meta">
            <strong>★ {Number(shop.rating_average).toFixed(1)}</strong>
            <span>{shop.rating_count} reviews</span>
            <span>{shop.address_text}</span>
          </div>
        </div>
      </div>
      <div className="section-heading">
        <div>
          <span className="section-kicker">Current catalogue</span>
          <h2>{shop.products.length} products from this shop</h2>
        </div>
      </div>
      {shop.products.length === 0 ? (
        <div className="empty-marketplace">
          <h3>No active products right now</h3>
          <p>Check back after this shop publishes new stock.</p>
        </div>
      ) : (
        <div className="marketplace-product-grid">
          {shop.products.map((product) => (
            <MarketplaceProductCard
              product={product}
              navigate={navigate}
              key={product.listing_id}
            />
          ))}
        </div>
      )}
    </section>
  );
}
