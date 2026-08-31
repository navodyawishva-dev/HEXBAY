import { useEffect, useRef } from "react";
import { useAuth } from "./contexts/AuthContext";
import { useSimpleRouter } from "./hooks/useSimpleRouter";
import PublicHeader from "./components/PublicHeader";
import SiteFooter from "./components/SiteFooter";
import HexBotWidget from "./components/HexBotWidget";
import HomePage from "./pages/HomePage";
import AboutPage from "./pages/AboutPage";
import ProductsPage from "./pages/ProductsPage";
import ProductDetailPage from "./pages/ProductDetailPage";
import ShopDetailPage from "./pages/ShopDetailPage";
import LoginPage from "./pages/LoginPage";
import RegisterChoicePage from "./pages/RegisterChoicePage";
import RegisterPage from "./pages/RegisterPage";
import SellLandingPage from "./pages/SellLandingPage";
import AccountPage from "./pages/AccountPage";
import BuyerAddressesPage from "./pages/BuyerAddressesPage";
import BuyerWishlistPage from "./pages/BuyerWishlistPage";
import BuyerCartPage from "./pages/BuyerCartPage";
import BuyerCheckoutPage from "./pages/BuyerCheckoutPage";
import BuyerOrdersPage from "./pages/BuyerOrdersPage";
import BuyerNotificationsPage from "./pages/BuyerNotificationsPage";
import BuyerOrderDetailPage from "./pages/BuyerOrderDetailPage";
import SellerOnboardingPage from "./pages/SellerOnboardingPage";
import SellerDashboardPage from "./pages/SellerDashboardPage";
import SellerProfilePage from "./pages/SellerProfilePage";
import SellerProductsPage from "./pages/SellerProductsPage";
import SellerInventoryPage from "./pages/SellerInventoryPage";
import SellerOrdersPage from "./pages/SellerOrdersPage";
import SellerReviewsPage from "./pages/SellerReviewsPage";
import SellerFinancePage from "./pages/SellerFinancePage";
import AdminShell from "./pages/admin/AdminShell";

function AccessMessage({ title, message, action, onAction }) {
  return (
    <section className="content-section page-section narrow-section">
      <div className="application-summary">
        <span className="section-kicker">Access notice</span>
        <h1>{title}</h1>
        <p>{message}</p>
        <button className="button button-primary" onClick={onAction}>
          {action}
        </button>
      </div>
    </section>
  );
}

export default function App() {
  const { user, loading, logout } = useAuth();
  const { path, search, navigate } = useSimpleRouter();
  const isAdminRoute = path.startsWith("/admin");
  const isXBoardRoute = path === "/x-board" || path === "/pc-builder";
  const isSeller = user?.role === "shop_owner";
  const isBuyerWorkspace = [
    "/account",
    "/wishlist",
    "/cart",
    "/checkout",
    "/orders",
    "/notifications",
    "/addresses",
  ].includes(path) || /^\/orders\/\d+$/.test(path);
  const isWorkspaceRoute = isBuyerWorkspace || path.startsWith("/seller/");
  const xBoardReturnLocation = useRef("/");

  useEffect(() => {
    if (!isXBoardRoute) {
      xBoardReturnLocation.current = `${path}${search}`;
    }
  }, [isXBoardRoute, path, search]);

  if (loading) {
    return <div className="route-loading full-page-loading">Loading Hexbay…</div>;
  }

  if (isAdminRoute) {
    if (!user) {
      return <LoginPage navigate={navigate} adminOnly />;
    }
    if (user.role !== "administrator") {
      return (
        <AccessMessage
          title="Administrator access required"
          message="You are currently signed in with a buyer or seller account. Sign out before using your private administrator account."
          action="Sign out and open admin sign in"
          onAction={async () => {
            await logout();
            navigate("/admin/login");
          }}
        />
      );
    }
    return <AdminShell path={path} navigate={navigate} />;
  }

  let page;
  if (path === "/") page = <HomePage navigate={navigate} />;
  else if (path === "/about") page = <AboutPage navigate={navigate} />;
  else if (isXBoardRoute) {
    page = isSeller ? (
      <AccessMessage
        title="Customer shopping assistant"
        message="HexBot and X Board are customer shopping tools. Your seller workspace contains the controls needed to manage your shop."
        action="Return to seller dashboard"
        onAction={() => navigate("/seller/dashboard")}
      />
    ) : (
      <div className="x-board-route-placeholder" aria-hidden="true" />
    );
  }
  else if (path === "/products") {
    page = <ProductsPage navigate={navigate} search={search} />;
  } else if (/^\/products\/\d+$/.test(path)) {
    page = (
      <ProductDetailPage
        productId={Number(path.split("/")[2])}
        navigate={navigate}
      />
    );
  } else if (/^\/shops\/\d+$/.test(path)) {
    page = (
      <ShopDetailPage
        shopId={Number(path.split("/")[2])}
        navigate={navigate}
      />
    );
  }
  else if (path === "/login") page = <LoginPage navigate={navigate} />;
  else if (path === "/register") page = <RegisterChoicePage navigate={navigate} />;
  else if (path === "/register/buyer") {
    page = <RegisterPage navigate={navigate} role="customer" />;
  } else if (path === "/register/seller") {
    page = <RegisterPage navigate={navigate} role="shop_owner" />;
  } else if (path === "/sell") page = <SellLandingPage navigate={navigate} />;
  else if (path === "/account") {
    page =
      user?.role === "customer" ? (
        <AccountPage navigate={navigate} path={path} />
      ) : (
        <AccessMessage
          title="Buyer account required"
          message="Sign in with a buyer account to open this area."
          action="Sign in"
          onAction={() => navigate("/login")}
        />
      );
  } else if (
    ["/wishlist", "/cart", "/checkout", "/orders", "/notifications", "/addresses"].includes(path)
    || /^\/orders\/\d+$/.test(path)
  ) {
    if (user?.role !== "customer") {
      page = (
        <AccessMessage
          title="Buyer account required"
          message="Sign in with a buyer account to use your wishlist, cart, addresses and orders."
          action="Buyer sign in"
          onAction={() => navigate("/login")}
        />
      );
    } else if (path === "/wishlist") {
      page = <BuyerWishlistPage navigate={navigate} path={path} />;
    } else if (path === "/cart") {
      page = <BuyerCartPage navigate={navigate} path={path} />;
    } else if (path === "/checkout") {
      page = <BuyerCheckoutPage navigate={navigate} />;
    } else if (path === "/orders") {
      page = <BuyerOrdersPage navigate={navigate} path={path} />;
    } else if (path === "/notifications") {
      page = <BuyerNotificationsPage navigate={navigate} path={path} />;
    } else if (path === "/addresses") {
      page = <BuyerAddressesPage navigate={navigate} path={path} />;
    } else {
      page = (
        <BuyerOrderDetailPage
          orderId={Number(path.split("/")[2])}
          navigate={navigate}
        />
      );
    }
  } else if (path === "/seller/onboarding") {
    page =
      user?.role === "shop_owner" ? (
        <SellerOnboardingPage navigate={navigate} />
      ) : (
        <AccessMessage
          title="Seller account required"
          message="Create or sign in with a seller account before submitting a shop."
          action="Create seller account"
          onAction={() => navigate("/register/seller")}
        />
      );
  } else if (path === "/seller/dashboard") {
    page =
      user?.role === "shop_owner" ? (
        <SellerDashboardPage navigate={navigate} path={path} />
      ) : (
        <AccessMessage
          title="Seller account required"
          message="This dashboard is available to registered sellers."
          action="Seller sign in"
          onAction={() => navigate("/login")}
        />
      );
  } else if (
    [
      "/seller/profile",
      "/seller/products",
      "/seller/inventory",
      "/seller/orders",
      "/seller/reviews",
      "/seller/finance",
    ].includes(path)
  ) {
    const sellerPages = {
      "/seller/profile": SellerProfilePage,
      "/seller/products": SellerProductsPage,
      "/seller/inventory": SellerInventoryPage,
      "/seller/orders": SellerOrdersPage,
      "/seller/reviews": SellerReviewsPage,
      "/seller/finance": SellerFinancePage,
    };
    const SellerPage = sellerPages[path];
    page =
      user?.role === "shop_owner" ? (
        <SellerPage navigate={navigate} path={path} />
      ) : (
        <AccessMessage
          title="Seller account required"
          message="This workspace is available to approved Hexbay sellers."
          action="Seller sign in"
          onAction={() => navigate("/login")}
        />
      );
  } else {
    page = (
      <AccessMessage
        title="Page not found"
        message="The page you requested does not exist in this Hexbay sprint."
        action="Return home"
        onAction={() => navigate("/")}
      />
    );
  }

  return (
    <div className={isWorkspaceRoute ? "site-shell workspace-shell" : "site-shell"}>
      <PublicHeader
        user={user}
        navigate={navigate}
        onLogout={async () => {
          await logout();
          navigate("/");
        }}
      />
      <main>{page}</main>
      {!isXBoardRoute && !isSeller && <SiteFooter navigate={navigate} />}
      {!isSeller && (
        <HexBotWidget
          navigate={navigate}
          dashboard={isXBoardRoute}
          onExitDashboard={() => navigate(xBoardReturnLocation.current)}
        />
      )}
    </div>
  );
}
