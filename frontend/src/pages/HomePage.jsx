import { useEffect, useState } from "react";
import { apiRequest, mediaUrl } from "../api/client";
import MarketplaceProductCard from "../components/MarketplaceProductCard";
import hexbotPcBuild from "../assets/hexbot/home/hexbot-pc-build.png";
import hexbotKeyboard from "../assets/hexbot/home/hexbot-keyboard.png";
import hexbotMouse from "../assets/hexbot/home/hexbot-mouse.png";
import hexbotFullSetup from "../assets/hexbot/home/hexbot-full-setup.png";
import hexbotChair from "../assets/hexbot/home/hexbot-chair.png";
import hexbotStreaming from "../assets/hexbot/home/hexbot-streaming.png";
import hexbotGaming from "../assets/hexbot/home/hexbot-gaming.png";

const categoryIcons = {
  laptops: "▰",
  processors: "CPU",
  motherboards: "◆",
  memory: "RAM",
  "graphics-cards": "GPU",
  "power-supplies": "PSU",
  storage: "SSD",
  "computer-cases": "▣",
  accessories: "⌁",
};

const heroSlides = [
  {
    src: hexbotMouse,
    alt: "HexBot presenting a gaming mouse",
    eyebrow: "Precision matters",
    title: "The right mouse, clearly compared.",
    description: "Shop gaming and productivity gear from approved sellers.",
  },
  {
    src: hexbotKeyboard,
    alt: "HexBot holding a blue gaming keyboard",
    eyebrow: "Feel every keystroke",
    title: "Find your perfect keyboard.",
    description: "Compare switches, layouts and prices without the guesswork.",
  },
  {
    src: hexbotPcBuild,
    alt: "HexBot presenting a custom gaming PC",
    eyebrow: "Build with confidence",
    title: "Your ideal PC starts here.",
    description: "Match the right components and compare trusted local offers.",
  },
  {
    src: hexbotFullSetup,
    alt: "HexBot gaming at a complete desktop setup",
    eyebrow: "Complete the whole desk",
    title: "Build a setup that works together.",
    description: "From the tower to the final accessory, HexBot keeps it connected.",
  },
  {
    src: hexbotChair,
    alt: "HexBot presenting a gaming chair",
    eyebrow: "Upgrade your comfort",
    title: "A better setup goes beyond specs.",
    description: "Discover the finishing touches for work, play and everything between.",
  },
  {
    src: hexbotStreaming,
    alt: "HexBot streaming with a microphone and laptop",
    eyebrow: "Create without compromise",
    title: "Gear made for your next idea.",
    description: "Find laptops and creator accessories that fit your workflow and budget.",
  },
  {
    src: hexbotGaming,
    alt: "HexBot gaming with a controller at a desktop setup",
    eyebrow: "Ready for the next round",
    title: "Level up with smarter choices.",
    description: "Compare complete gaming setups from shops you can trust.",
  },
];

export default function HomePage({ navigate }) {
  const [categories, setCategories] = useState([]);
  const [featured, setFeatured] = useState([]);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [activeSlide, setActiveSlide] = useState(0);
  const [previousSlide, setPreviousSlide] = useState(null);
  const [isCarouselPaused, setIsCarouselPaused] = useState(false);

  useEffect(() => {
    Promise.all([apiRequest("/categories"), apiRequest("/featured-products")])
      .then(([categoryResponse, productResponse]) => {
        setCategories(categoryResponse.data.categories);
        setFeatured(productResponse.data.products);
      })
      .catch(() => {
        setCategories([]);
        setFeatured([]);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    const nextImage = new Image();
    nextImage.src = heroSlides[(activeSlide + 1) % heroSlides.length].src;

    if (isCarouselPaused) return undefined;

    const timer = window.setInterval(() => {
      setActiveSlide((currentSlide) => {
        setPreviousSlide(currentSlide);
        return (currentSlide + 1) % heroSlides.length;
      });
    }, 5600);

    return () => window.clearInterval(timer);
  }, [activeSlide, isCarouselPaused]);

  const selectSlide = (slideIndex) => {
    if (slideIndex === activeSlide) return;
    setPreviousSlide(activeSlide);
    setActiveSlide(slideIndex);
  };

  const submitSearch = () => {
    const query = search.trim();
    navigate(query ? `/products?search=${encodeURIComponent(query)}` : "/products");
  };

  const currentHero = heroSlides[activeSlide];

  return (
    <div className="home-dashboard">
      <section className="hero-section home-hero">
        <div className="hero-content">
          <span className="section-kicker">Smarter tech starts here</span>
          <h1>Build better. Play harder. Choose smarter.</h1>
          <p>
            Compare laptops, PC components and complete setups from approved
            local technology shops—with HexBot beside you.
          </p>
          <div className="hero-search">
            <label className="sr-only" htmlFor="hero-search">
              Search technology products
            </label>
            <input
              id="hero-search"
              placeholder="Search laptops, graphics cards, SSDs…"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter") submitSearch();
              }}
            />
            <button type="button" onClick={submitSearch}>
              Search
            </button>
          </div>
          <div className="trust-row">
            <span>✓ Approved shops</span>
            <span>✓ Compare vendors</span>
            <span>✓ Compatibility guidance</span>
          </div>
          <button
            type="button"
            className="hero-builder-link"
            onClick={() => navigate("/x-board")}
          >
            Planning a desktop? Build it with HexBot in X Board →
          </button>
        </div>
        <div
          className="hero-visual hero-carousel"
          role="region"
          aria-roledescription="carousel"
          aria-label="HexBot product highlights"
        >
          {previousSlide !== null && (
            <img
              className="hero-slide hero-slide-previous"
              src={heroSlides[previousSlide].src}
              alt=""
              aria-hidden="true"
            />
          )}
          <img
            key={currentHero.src}
            className="hero-slide hero-slide-active"
            src={currentHero.src}
            alt={currentHero.alt}
            fetchpriority={activeSlide === 0 ? "high" : "auto"}
          />
          <div className="hero-image-shade" />
          <div className="hero-slide-copy" aria-live="polite">
            <span>{currentHero.eyebrow}</span>
            <strong>{currentHero.title}</strong>
            <small>{currentHero.description}</small>
          </div>
          <div className="hero-carousel-controls">
            <div className="hero-thumbnails" role="tablist" aria-label="Choose a HexBot highlight">
              {heroSlides.map((slide, slideIndex) => (
                <button
                  type="button"
                  role="tab"
                  aria-selected={slideIndex === activeSlide}
                  aria-label={`Show slide ${slideIndex + 1}: ${slide.title}`}
                  className={slideIndex === activeSlide ? "is-active" : ""}
                  onClick={() => selectSlide(slideIndex)}
                  key={slide.src}
                >
                  <img src={slide.src} alt="" loading="lazy" />
                </button>
              ))}
            </div>
            <button
              type="button"
              className="hero-pause-button"
              aria-label={isCarouselPaused ? "Resume image rotation" : "Pause image rotation"}
              onClick={() => setIsCarouselPaused((paused) => !paused)}
            >
              {isCarouselPaused ? "▶" : "Ⅱ"}
            </button>
          </div>
        </div>
      </section>

      <section className="content-section" id="categories">
        <div className="section-heading">
          <div>
            <span className="section-kicker">Browse comfortably</span>
            <h2>Shop by category</h2>
          </div>
          <button className="text-link" onClick={() => navigate("/products")}>
            View all products →
          </button>
        </div>
        <div className="category-grid">
          {loading
            ? Array.from({ length: 6 }, (_, index) => (
                <div className="category-card skeleton-card" key={index} />
              ))
            : categories.map((category) => (
                <button
                  className="category-card"
                  type="button"
                  key={category.id}
                  onClick={() =>
                    navigate(`/products?category=${encodeURIComponent(category.slug)}`)
                  }
                >
                  <span className={`category-image ${category.representative_image_filename ? "has-image" : ""}`}>
                    {category.representative_image_filename ? (
                      <img
                        src={mediaUrl("product-images", category.representative_image_filename)}
                        alt=""
                        loading="lazy"
                      />
                    ) : (
                      categoryIcons[category.slug] ?? "TECH"
                    )}
                  </span>
                  <strong>{category.name}</strong>
                  <small>{category.description}</small>
                </button>
              ))}
        </div>
      </section>

      <section className="soft-section">
        <div className="section-heading">
          <div>
            <span className="section-kicker">Ready to compare</span>
            <h2>Featured technology</h2>
          </div>
        </div>
        {loading ? (
          <div className="marketplace-product-grid">
            {Array.from({ length: 4 }, (_, index) => (
              <div className="marketplace-product-card skeleton-card" key={index} />
            ))}
          </div>
        ) : featured.length ? (
          <div className="marketplace-product-grid">
            {featured.map((product) => (
              <MarketplaceProductCard
                product={product}
                navigate={navigate}
                key={product.id}
              />
            ))}
          </div>
        ) : (
          <div className="empty-marketplace">
            <div className="empty-illustration">⌁</div>
            <h3>Fresh products will appear here soon</h3>
            <p>Approved, active seller listings are shown automatically.</p>
          </div>
        )}
      </section>

      <section className="seller-cta">
        <div>
          <span className="section-kicker light-kicker">For technology shops</span>
          <h2>Grow your shop with Hexbay.</h2>
          <p>
            Join a focused marketplace, reach technology buyers and manage your
            products from one seller dashboard.
          </p>
        </div>
        <button className="button button-light" onClick={() => navigate("/sell")}>
          Start selling
        </button>
      </section>
    </div>
  );
}
