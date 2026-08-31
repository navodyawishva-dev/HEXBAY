import logo from "../assets/brand/hexbay-logo-dark-v2.png";

export default function BrandLogo({ onClick, compact = false }) {
  return (
    <button
      className={`brand-logo ${compact ? "brand-logo-compact" : ""}`}
      type="button"
      onClick={onClick}
      aria-label="Go to Hexbay homepage"
    >
      <img src={logo} alt="Hexbay" />
    </button>
  );
}

