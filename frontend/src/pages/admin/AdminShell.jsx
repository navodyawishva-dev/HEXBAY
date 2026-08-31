import BrandLogo from "../../components/BrandLogo";
import { useAuth } from "../../contexts/AuthContext";
import AdminDashboardPage from "./AdminDashboardPage";
import AdminUsersPage from "./AdminUsersPage";
import AdminApplicationsPage from "./AdminApplicationsPage";
import AdminCommissionPage from "./AdminCommissionPage";
import AdminAuditPage from "./AdminAuditPage";
import AdminCatalogPage from "./AdminCatalogPage";
import AdminModerationPage from "./AdminModerationPage";
import AdminTrustPage from "./AdminTrustPage";

const navItems = [
  ["/admin/dashboard", "Overview"],
  ["/admin/users", "Accounts"],
  ["/admin/applications", "Shop applications"],
  ["/admin/catalog", "Categories & specs"],
  ["/admin/moderation", "Listing moderation"],
  ["/admin/trust", "Trust & safety"],
  ["/admin/commission", "Finance & payouts"],
  ["/admin/audit", "Audit records"],
];

export default function AdminShell({ path, navigate }) {
  const { user, logout } = useAuth();
  let content = <AdminDashboardPage navigate={navigate} />;
  if (path === "/admin/users") content = <AdminUsersPage />;
  if (path === "/admin/applications") content = <AdminApplicationsPage />;
  if (path === "/admin/catalog") content = <AdminCatalogPage />;
  if (path === "/admin/moderation") content = <AdminModerationPage />;
  if (path === "/admin/trust") content = <AdminTrustPage />;
  if (path === "/admin/commission") content = <AdminCommissionPage />;
  if (path === "/admin/audit") content = <AdminAuditPage />;

  return (
    <div className="admin-shell">
      <aside className="admin-sidebar">
        <BrandLogo compact onClick={() => navigate("/admin/dashboard")} />
        <div className="admin-label">Administrator</div>
        <nav aria-label="Administrator navigation">
          {navItems.map(([route, label]) => (
            <button
              className={path === route ? "active" : ""}
              type="button"
              key={route}
              onClick={() => navigate(route)}
            >
              <span>{label.slice(0, 1)}</span>
              {label}
            </button>
          ))}
        </nav>
        <div className="admin-sidebar-footer">
          <small>Signed in as</small>
          <strong>{user?.email}</strong>
          <button
            type="button"
            onClick={async () => {
              await logout();
              navigate("/admin/login");
            }}
          >
            Sign out
          </button>
        </div>
      </aside>
      <main className="admin-main">{content}</main>
    </div>
  );
}
