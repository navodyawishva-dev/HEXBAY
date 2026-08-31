import { useEffect, useState } from "react";
import { apiRequest } from "../api/client";
import { useAuth } from "../contexts/AuthContext";
import SellerNav from "../components/SellerNav";
import StatusBadge from "../components/StatusBadge";

export default function SellerReviewsPage({ navigate, path }) {
  const { token } = useAuth();
  const [reviews, setReviews] = useState([]);
  const [message, setMessage] = useState("");

  useEffect(() => {
    apiRequest("/seller/reviews", { token })
      .then((response) => setReviews(response.data.reviews))
      .catch((error) => setMessage(error.message));
  }, [token]);

  return (
    <section className="content-section page-section">
      <SellerNav path={path} navigate={navigate} />
      <div className="account-hero">
        <div>
          <span className="section-kicker">Customer feedback</span>
          <h1 className="page-title">Product reviews</h1>
          <p>Verified-purchase reviews relating only to your shop.</p>
        </div>
      </div>
      {message && <div className="alert alert-info">{message}</div>}
      <div className="review-grid">
        {reviews.length === 0 ? (
          <div className="admin-panel compact-empty">
            Reviews will appear after customers complete purchases.
          </div>
        ) : (
          reviews.map((review) => (
            <article className="admin-panel review-card" key={review.id}>
              <div className="review-stars" aria-label={`${review.rating} out of 5`}>
                {"★".repeat(Number(review.rating))}
                {"☆".repeat(5 - Number(review.rating))}
              </div>
              <h2>{review.title || review.product_name}</h2>
              <p>{review.review_text || "No written comment."}</p>
              <div>
                <strong>{review.product_name}</strong>
                <small>{review.customer_email}</small>
                {Boolean(Number(review.is_verified_purchase)) && (
                  <StatusBadge status="verified purchase" />
                )}
              </div>
            </article>
          ))
        )}
      </div>
    </section>
  );
}
