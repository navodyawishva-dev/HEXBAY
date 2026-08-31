export default function RegisterChoicePage({ navigate }) {
  return (
    <section className="auth-layout">
      <div className="auth-intro">
        <span className="section-kicker">Join the marketplace</span>
        <h1>How would you like to use Hexbay?</h1>
        <p>Choose one account type for this first version of Hexbay.</p>
      </div>
      <div className="account-choice-grid">
        <button
          className="account-choice"
          type="button"
          onClick={() => navigate("/register/buyer")}
        >
          <span className="choice-icon">B</span>
          <small>Buyer account</small>
          <h2>Shop with confidence</h2>
          <p>Browse, compare, save favourites and purchase from approved shops.</p>
          <strong>Continue as a buyer →</strong>
        </button>
        <button
          className="account-choice seller-choice"
          type="button"
          onClick={() => navigate("/register/seller")}
        >
          <span className="choice-icon">S</span>
          <small>Seller account</small>
          <h2>Sell technology products</h2>
          <p>Create a shop application and sell after administrator approval.</p>
          <strong>Continue as a seller →</strong>
        </button>
      </div>
      <button className="text-link centered-link" onClick={() => navigate("/login")}>
        Already have an account? Sign in
      </button>
    </section>
  );
}

