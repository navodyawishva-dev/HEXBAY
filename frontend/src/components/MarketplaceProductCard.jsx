import { mediaUrl } from "../api/client";

export default function MarketplaceProductCard({ product, navigate }) {
  const productId = product.id ?? product.product_id;
  const name = product.name ?? product.product_name;
  const price = product.starting_price ?? product.price;
  const available = Number(
    product.available_quantity ?? product.quantity_available ?? 0,
  );

  return (
    <article className="marketplace-product-card">
      <button
        type="button"
        className="marketplace-product-visual"
        onClick={() => navigate(`/products/${productId}`)}
      >
        {product.image_filename ? (
          <img
            src={mediaUrl("product-images", product.image_filename)}
            alt={name}
          />
        ) : (
          <span>{(product.brand_name || name).slice(0, 1).toUpperCase()}</span>
        )}
      </button>
      <div className="marketplace-product-body">
        <span className="product-category">
          {product.category_name || "Technology"}
        </span>
        <button
          type="button"
          className="product-title-button"
          onClick={() => navigate(`/products/${productId}`)}
        >
          {name}
        </button>
        <p>
          {product.brand_name} {product.model ? `· ${product.model}` : ""}
        </p>
        <div className="product-market-meta">
          <strong>LKR {Number(price).toLocaleString()}</strong>
          <span className={available > 0 ? "stock-ready" : "stock-empty"}>
            {available > 0 ? `${available} available` : "Out of stock"}
          </span>
        </div>
        <div className="product-market-footer">
          <span>
            {product.offer_count
              ? `${product.offer_count} seller${Number(product.offer_count) === 1 ? "" : "s"}`
              : product.condition_type}
          </span>
          <button
            type="button"
            className="text-link"
            onClick={() => navigate(`/products/${productId}`)}
          >
            Compare
          </button>
        </div>
      </div>
    </article>
  );
}
