import BrandLogo from "./BrandLogo";

export default function SiteFooter({ navigate }) {
  return (
    <footer className="site-footer">
      <div className="footer-inner">
        <div>
          <BrandLogo compact onClick={() => navigate("/")} />
          <p>A trusted local marketplace for technology products.</p>
        </div>
        <div className="footer-links">
          <div>
            <strong>Marketplace</strong>
            <button onClick={() => navigate("/products")}>Browse products</button>
            <button onClick={() => navigate("/x-board")}>Ask HexBot</button>
            <button onClick={() => navigate("/about")}>About Hexbay</button>
            <button onClick={() => navigate("/sell")}>Sell on Hexbay</button>
          </div>
          <div>
            <strong>Support</strong>
            <span>Safe shopping</span>
            <span>Verified shops</span>
          </div>
        </div>
      </div>
      <div className="footer-bottom">
        © 2026 Hexbay · Smarter technology shopping, clearly explained
      </div>
    </footer>
  );
}
