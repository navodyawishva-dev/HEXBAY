import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import MarketplaceProductCard from "../components/MarketplaceProductCard";
import { useAuth } from "../contexts/AuthContext";

const emptyFilters = {
  search: "",
  category: "",
  brand_id: "",
  min_price: "",
  max_price: "",
  available: true,
  sort: "featured",
};

const filtersFromSearch = (search) => {
  const params = new URLSearchParams(search);
  return {
    ...emptyFilters,
    search: params.get("search") ?? "",
    category: params.get("category") ?? "",
  };
};

export default function ProductsPage({ navigate, search = "" }) {
  const { user, token } = useAuth();
  const [categories, setCategories] = useState([]);
  const [draft, setDraft] = useState(() => filtersFromSearch(search));
  const [filters, setFilters] = useState(() => filtersFromSearch(search));
  const [page, setPage] = useState(1);
  const [data, setData] = useState({
    products: [],
    brands: [],
    pagination: { page: 1, pages: 1, total: 0 },
  });
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");

  useEffect(() => {
    apiRequest("/categories")
      .then((response) => setCategories(response.data.categories))
      .catch((error) => setMessage(error.message));
  }, []);

  useEffect(() => {
    const nextFilters = filtersFromSearch(search);
    setDraft(nextFilters);
    setFilters(nextFilters);
    setPage(1);
  }, [search]);

  useEffect(() => {
    const query = new URLSearchParams();
    Object.entries({ ...filters, page, per_page: 12 }).forEach(([key, value]) => {
      if (value !== "" && value !== false) query.set(key, String(value));
    });
    setLoading(true);
    setMessage("");
    apiRequest(`/products?${query}`)
      .then((response) => setData(response.data))
      .catch((error) => setMessage(error.message))
      .finally(() => setLoading(false));
  }, [filters, page]);

  const apply = (event) => {
    event.preventDefault();
    setPage(1);
    setFilters(draft);
    if (user?.role === "customer" && draft.search.trim()) {
      apiRequest("/interactions", {
        method: "POST",
        token,
        body: { event_type: "search", query: draft.search.trim() },
      }).catch(() => {});
    }
  };

  const clear = () => {
    setDraft(emptyFilters);
    setFilters(emptyFilters);
    setPage(1);
  };

  return (
    <section className="content-section page-section">
      <div className="marketplace-heading">
        <div>
          <span className="section-kicker">Approved local sellers</span>
          <h1 className="page-title">Technology marketplace</h1>
          <p>Search once, compare every approved offer comfortably.</p>
        </div>
        <strong>{data.pagination.total} products</strong>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      <div className="catalogue-layout live-catalogue">
        <aside className="catalogue-filter-panel">
          <form onSubmit={apply}>
            <label>
              Search
              <input
                value={draft.search}
                placeholder="Laptop, GPU, SSD…"
                onChange={(event) =>
                  setDraft({ ...draft, search: event.target.value })
                }
              />
            </label>
            <label>
              Category
              <select
                value={draft.category}
                onChange={(event) =>
                  setDraft({ ...draft, category: event.target.value })
                }
              >
                <option value="">All categories</option>
                {categories.map((category) => (
                  <option value={category.slug} key={category.id}>
                    {category.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Brand
              <select
                value={draft.brand_id}
                onChange={(event) =>
                  setDraft({ ...draft, brand_id: event.target.value })
                }
              >
                <option value="">All brands</option>
                {data.brands.map((brand) => (
                  <option value={brand.id} key={brand.id}>
                    {brand.name}
                  </option>
                ))}
              </select>
            </label>
            <div className="price-filter-grid">
              <label>
                Minimum LKR
                <input
                  type="number"
                  min="0"
                  value={draft.min_price}
                  onChange={(event) =>
                    setDraft({ ...draft, min_price: event.target.value })
                  }
                />
              </label>
              <label>
                Maximum LKR
                <input
                  type="number"
                  min="0"
                  value={draft.max_price}
                  onChange={(event) =>
                    setDraft({ ...draft, max_price: event.target.value })
                  }
                />
              </label>
            </div>
            <label className="check-row catalogue-check">
              <input
                type="checkbox"
                checked={draft.available}
                onChange={(event) =>
                  setDraft({ ...draft, available: event.target.checked })
                }
              />
              In-stock products only
            </label>
            <button className="button button-primary full-button">
              Apply filters
            </button>
            <button
              type="button"
              className="button button-ghost full-button"
              onClick={clear}
            >
              Clear
            </button>
          </form>
        </aside>
        <div>
          <div className="catalogue-toolbar">
            <span>
              Page {data.pagination.page} of {data.pagination.pages}
            </span>
            <select
              value={filters.sort}
              onChange={(event) => {
                const next = { ...filters, sort: event.target.value };
                setFilters(next);
                setDraft(next);
                setPage(1);
              }}
            >
              <option value="featured">Featured</option>
              <option value="price_low">Price: low to high</option>
              <option value="price_high">Price: high to low</option>
              <option value="rating">Best-rated shop</option>
              <option value="newest">Newest listings</option>
              <option value="name">Product name</option>
            </select>
          </div>
          {loading ? (
            <div className="marketplace-loading-grid">
              {Array.from({ length: 6 }, (_, index) => (
                <div className="category-card skeleton-card" key={index} />
              ))}
            </div>
          ) : data.products.length === 0 ? (
            <div className="empty-marketplace catalogue-empty">
              <h3>No approved products match these filters</h3>
              <p>Try another category, price range or search phrase.</p>
              <button className="button button-primary" onClick={clear}>
                Show all products
              </button>
            </div>
          ) : (
            <div className="marketplace-product-grid">
              {data.products.map((product) => (
                <MarketplaceProductCard
                  product={product}
                  navigate={navigate}
                  key={product.id}
                />
              ))}
            </div>
          )}
          {data.pagination.pages > 1 && (
            <div className="catalogue-pagination">
              <button
                className="button button-ghost"
                disabled={page <= 1}
                onClick={() => setPage((current) => current - 1)}
              >
                Previous
              </button>
              <span>{page}</span>
              <button
                className="button button-ghost"
                disabled={page >= data.pagination.pages}
                onClick={() => setPage((current) => current + 1)}
              >
                Next
              </button>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
