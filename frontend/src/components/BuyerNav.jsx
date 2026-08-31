const links = [
  ["/account", "Overview"],
  ["/wishlist", "Wishlist"],
  ["/cart", "Cart"],
  ["/orders", "Track orders"],
  ["/notifications", "Notifications"],
  ["/addresses", "Addresses"],
];

export default function BuyerNav({ path, navigate, unreadCount = 0 }) {
  return (
    <nav className="buyer-nav" aria-label="Buyer account navigation">
      {links.map(([href, label]) => (
        <button
          type="button"
          className={path === href ? "active" : ""}
          onClick={() => navigate(href)}
          key={href}
        >
          {label}{href === "/notifications" && unreadCount > 0 ? ` (${unreadCount})` : ""}
        </button>
      ))}
    </nav>
  );
}
