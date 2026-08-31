import aboutHero from "../assets/about/hexbot-about-hero.png";

const pillars = [
  {
    number: "01",
    title: "Discover with context",
    copy: "Browse technology from local shops with the specifications, availability and details that matter kept together.",
  },
  {
    number: "02",
    title: "Choose with confidence",
    copy: "Compare products clearly and understand the practical differences before deciding what fits your needs.",
  },
  {
    number: "03",
    title: "Build intelligently",
    copy: "Turn a budget and ordinary-language requirements into complete PC recommendations checked for compatibility.",
  },
];

const principles = [
  ["Clarity first", "Useful explanations instead of unexplained product lists."],
  ["Compatibility matters", "PC components are checked together, not recommended in isolation."],
  ["Your needs lead", "Budget, purpose and preferred specifications shape every result."],
  ["Local discovery", "Customers can discover products and offers from technology shops in one place."],
];

export default function AboutPage({ navigate }) {
  return (
    <div className="about-page">
      <section className="about-hero" aria-labelledby="about-title">
        <div className="about-hero-copy">
          <span className="about-eyebrow">This is HEXBAY</span>
          <h1 id="about-title">Technology shopping, made understandable.</h1>
          <p>
            HEXBAY brings products, local technology shops and intelligent guidance
            into one clear experience—so finding the right setup feels exciting,
            not overwhelming.
          </p>
          <div className="about-hero-actions">
            <button className="button button-primary" onClick={() => navigate("/products")}>
              Explore the marketplace
            </button>
            <button className="button about-outline-button" onClick={() => navigate("/x-board")}>
              Meet HexBot
            </button>
          </div>
          <div className="about-signal-row" aria-label="Hexbay highlights">
            <span><i /> Clear comparisons</span>
            <span><i /> Compatible builds</span>
            <span><i /> Local shops</span>
          </div>
        </div>

        <figure className="about-hero-visual">
          <img
            src={aboutHero}
            alt="HexBot waving inside a futuristic blue gaming room"
          />
          <figcaption>
            <span>YOUR SMART TECH GUIDE</span>
            <strong>Ask. Understand. Level up.</strong>
          </figcaption>
        </figure>
      </section>

      <section className="about-purpose content-section" aria-labelledby="about-purpose-title">
        <header className="about-section-heading">
          <div>
            <span className="section-kicker">Why HEXBAY exists</span>
            <h2 id="about-purpose-title">The right technology should feel easier to find.</h2>
          </div>
          <p>
            Specifications can be confusing and compatible parts are easy to get
            wrong. HEXBAY turns that complexity into a guided journey from first
            question to confident choice.
          </p>
        </header>

        <div className="about-pillar-grid">
          {pillars.map((pillar) => (
            <article className="about-pillar-card" key={pillar.number}>
              <span>{pillar.number}</span>
              <h3>{pillar.title}</h3>
              <p>{pillar.copy}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="about-hexbot-story content-section" aria-labelledby="about-hexbot-title">
        <div className="about-hexbot-copy">
          <span className="about-eyebrow">Meet your guide</span>
          <h2 id="about-hexbot-title">HexBot starts with the way you naturally speak.</h2>
          <p>
            Tell HexBot your budget, what you want to do, and the specifications
            you care about. It asks the useful follow-up questions, checks the live
            HEXBAY catalogue and explains the result on X Board.
          </p>
          <button className="button button-light" onClick={() => navigate("/x-board")}>
            Start a conversation
          </button>
        </div>
        <ol className="about-journey" aria-label="How HexBot helps">
          <li><span>1</span><div><strong>Tell us what you need</strong><p>Start with ordinary language—no technical form required.</p></div></li>
          <li><span>2</span><div><strong>Confirm what matters</strong><p>Your budget and specifications stay visible before the search begins.</p></div></li>
          <li><span>3</span><div><strong>See the reasoning</strong><p>Review compatible choices, prices, trade-offs and clear explanations.</p></div></li>
        </ol>
      </section>

      <section className="about-principles content-section" aria-labelledby="about-principles-title">
        <div className="about-principles-intro">
          <span className="section-kicker">What guides us</span>
          <h2 id="about-principles-title">Useful technology. Human explanations.</h2>
        </div>
        <div className="about-principles-grid">
          {principles.map(([title, copy]) => (
            <article key={title}>
              <i aria-hidden="true" />
              <div><h3>{title}</h3><p>{copy}</p></div>
            </article>
          ))}
        </div>
      </section>

      <section className="about-final-cta content-section">
        <div>
          <span className="about-eyebrow">Ready when you are</span>
          <h2>Find your next piece of technology with HEXBAY.</h2>
        </div>
        <div>
          <button className="button button-light" onClick={() => navigate("/products")}>
            Browse products
          </button>
          <button className="button about-cta-dark" onClick={() => navigate("/x-board")}>
            Ask HexBot
          </button>
        </div>
      </section>
    </div>
  );
}
