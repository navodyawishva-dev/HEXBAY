const items = [
  ["/seller/dashboard", "Overview"],
  ["/seller/profile", "Shop profile"],
  ["/seller/products", "Products"],
  ["/seller/inventory", "Inventory"],
  ["/seller/orders", "Orders"],
  ["/seller/reviews", "Reviews"],
  ["/seller/finance", "Sales & payouts"],
];

export default function SellerNav({ path, navigate }) {
  return (
    <nav className="seller-workspace-nav" aria-label="Seller workspace">
      {items.map(([route, label]) => (
        <button
          className={path === route ? "active" : ""}
          type="button"
          key={route}
          onClick={() => navigate(route)}
        >
          {label}
        </button>
      ))}
    </nav>
  );
}
