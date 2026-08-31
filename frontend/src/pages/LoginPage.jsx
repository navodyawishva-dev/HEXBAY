import { useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import FieldError from "../components/FieldError";
import PasswordField from "../components/PasswordField";

export default function LoginPage({ navigate, adminOnly = false }) {
  const { login, logout } = useAuth();
  const [form, setForm] = useState({ email: "", password: "" });
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
      const user = await login(form);
      if (adminOnly && user.role !== "administrator") {
        await logout();
        throw new Error("This sign-in page is for Hexbay administrators.");
      }
      const buyerReturnPath = window.sessionStorage.getItem("hexbay_post_login_path");
      navigate(
        user.role === "administrator"
          ? "/admin/dashboard"
          : user.role === "shop_owner"
            ? "/seller/dashboard"
            : buyerReturnPath || "/",
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
      <div className="auth-card" aria-labelledby="login-title">
      <p className="section-kicker">{adminOnly ? "Restricted access" : "Welcome back"}</p>
      <h1 id="login-title">{adminOnly ? "Administrator sign in" : "Sign in to Hexbay"}</h1>
      <p className="muted">
        {adminOnly
          ? "Use an authorized administrator account."
          : "Continue shopping or manage your technology shop."}
      </p>

      {message && <div className="alert alert-error">{message}</div>}

      <form onSubmit={submit} noValidate>
        <label htmlFor="email">Email address</label>
        <input
          id="email"
          name="email"
          type="email"
          autoComplete="email"
          value={form.email}
          onChange={update}
          required
        />
        <FieldError errors={errors.email} />

        <label htmlFor="password">Password</label>
        <PasswordField
          id="password"
          name="password"
          autoComplete="current-password"
          value={form.password}
          onChange={update}
          required
        />
        <FieldError errors={errors.password} />

        <button className="primary-button" disabled={submitting}>
          {submitting ? "Signing in…" : "Sign in"}
        </button>
      </form>

      {!adminOnly && (
        <button
          className="text-button"
          type="button"
          onClick={() => navigate("/register")}
        >
          New to Hexbay? Create an account
        </button>
      )}
      <button className="text-button subtle-link" type="button" onClick={() => navigate("/")}>
        Return to marketplace
      </button>
      </div>
    </section>
  );
}
