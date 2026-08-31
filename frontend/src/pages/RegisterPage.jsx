import { useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import FieldError from "../components/FieldError";
import PasswordField from "../components/PasswordField";

const initialForm = {
  first_name: "",
  last_name: "",
  email: "",
  phone: "",
  business_name: "",
  password: "",
};

export default function RegisterPage({ navigate, role }) {
  const { register } = useAuth();
  const accountRole = role === "shop_owner" ? "shop_owner" : "customer";
  const [form, setForm] = useState(initialForm);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const update = (event) =>
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }));

  const submit = async (event) => {
    event.preventDefault();
    setErrors({});
    setMessage("");
    setSubmitting(true);
    try {
      await register(accountRole, form);
      const buyerReturnPath = window.sessionStorage.getItem("hexbay_post_login_path");
      navigate(
        accountRole === "shop_owner"
          ? "/seller/onboarding"
          : buyerReturnPath || "/"
      );
      window.sessionStorage.removeItem("hexbay_post_login_path");
    } catch (error) {
      setMessage(error.message);
      setErrors(error.validationErrors ?? {});
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section className="auth-page">
      <div className="auth-card auth-card-wide" aria-labelledby="register-title">
      <p className="section-kicker">
        {accountRole === "shop_owner" ? "Seller registration" : "Buyer registration"}
      </p>
      <h1 id="register-title">
        {accountRole === "shop_owner" ? "Start selling on Hexbay" : "Create your buyer account"}
      </h1>
      <p className="muted">
        {accountRole === "shop_owner"
          ? "First create your seller identity, then submit your shop for review."
          : "Browse products and purchase from approved local technology shops."}
      </p>

      {message && <div className="alert alert-error">{message}</div>}

      <form className="form-grid" onSubmit={submit} noValidate>
        {[
          ["first_name", "First name", "text"],
          ["last_name", "Last name", "text"],
          ["email", "Email address", "email"],
          ["phone", "Telephone (optional)", "tel"],
        ].map(([name, label, type]) => (
          <div className="form-field" key={name}>
            <label htmlFor={name}>{label}</label>
            <input
              id={name}
              name={name}
              type={type}
              value={form[name]}
              onChange={update}
              autoComplete={name === "email" ? "email" : "off"}
            />
            <FieldError errors={errors[name]} />
          </div>
        ))}

        {accountRole === "shop_owner" && (
          <div className="form-field full-width">
            <label htmlFor="business_name">Business name</label>
            <input
              id="business_name"
              name="business_name"
              value={form.business_name}
              onChange={update}
            />
            <FieldError errors={errors.business_name} />
          </div>
        )}

        <div className="form-field full-width">
          <label htmlFor="password">Password</label>
          <PasswordField
            id="password"
            name="password"
            autoComplete="new-password"
            value={form.password}
            onChange={update}
          />
          <p className="hint">10+ characters with uppercase, lowercase, and a number.</p>
          <FieldError errors={errors.password} />
        </div>

        <button className="primary-button full-width" disabled={submitting}>
          {submitting ? "Creating account…" : "Create account"}
        </button>
      </form>

      <button className="text-button" type="button" onClick={() => navigate("/login")}>
        Already registered? Sign in
      </button>
      </div>
    </section>
  );
}
