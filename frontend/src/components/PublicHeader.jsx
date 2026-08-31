import BrandLogo from "./BrandLogo";

export default function PublicHeader({ user, navigate, onLogout }) {
  const isSeller = user?.role === "shop_owner";

  return (
    <header className="public-header">
      <div className="header-inner">
        <BrandLogo onClick={() => navigate(isSeller ? "/seller/dashboard" : "/")} />
        <nav className="main-nav" aria-label="Main navigation">
          {isSeller ? (
            <>
              <button type="button" onClick={() => navigate("/seller/dashboard")}>Dashboard</button>
              <button type="button" onClick={() => navigate("/seller/products")}>Manage products</button>
              <button type="button" onClick={() => navigate("/seller/orders")}>Orders</button>
              <button type="button" onClick={() => navigate("/seller/profile")}>Shop profile</button>
            </>
          ) : (
            <>
              <button type="button" onClick={() => navigate("/products")}>Products</button>
              <button type="button" onClick={() => navigate("/x-board")}>Ask HexBot</button>
              <button
                type="button"
                onClick={() => {
                  navigate("/");
                  window.setTimeout(() => {
                    document.getElementById("categories")?.scrollIntoView({ behavior: "smooth" });
                  }, 0);
                }}
              >
                Categories
              </button>
              <button type="button" onClick={() => navigate("/sell")}>Sell on Hexbay</button>
              <button type="button" onClick={() => navigate("/about")}>About us</button>
            </>
          )}
        </nav>
        <div className="header-actions">
          {isSeller ? (
            <>
              <button className="button button-ghost" type="button" onClick={() => navigate("/products")}>
                View marketplace
              </button>
              <button className="button button-dark" type="button" onClick={onLogout}>
                Sign out
              </button>
            </>
          ) : user ? (
            <>
              {user.role === "customer" && (
                <>
                  <button className="button button-ghost header-cart-button" type="button" onClick={() => navigate("/cart")}>Cart</button>
                  <button className="button button-ghost header-notification-button" type="button" onClick={() => navigate("/notifications")}>Updates</button>
                </>
              )}
              <button
                className="button button-ghost account-button"
                type="button"
                onClick={() =>
                  navigate(
                    user.role === "administrator"
                      ? "/admin/dashboard"
                      : user.role === "shop_owner"
                        ? "/seller/dashboard"
                        : "/account",
                  )
                }
              >
                My account
              </button>
              <button className="button button-dark" type="button" onClick={onLogout}>
                Sign out
              </button>
            </>
          ) : (
            <>
              <button
                className="button button-ghost"
                type="button"
                onClick={() => navigate("/login")}
              >
                Sign in
              </button>
              <button
                className="button button-primary"
                type="button"
                onClick={() => navigate("/register")}
              >
                Join Hexbay
              </button>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
