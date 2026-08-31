import { useEffect, useState } from "react";
import { apiRequest } from "../../api/client";
import { useAuth } from "../../contexts/AuthContext";

export default function AdminAuditPage() {
  const { token } = useAuth();
  const [logs, setLogs] = useState([]);
  const [message, setMessage] = useState("");

  useEffect(() => {
    apiRequest("/admin/audit-logs", { token })
      .then((response) => setLogs(response.data.items))
      .catch((error) => setMessage(error.message));
  }, [token]);

  return (
    <>
      <div className="admin-page-heading">
        <div>
          <span className="section-kicker">Governance</span>
          <h1>Audit records</h1>
          <p>Important authentication and administrator actions are append-only.</p>
        </div>
      </div>
      {message && <div className="alert alert-error">{message}</div>}
      <section className="admin-panel">
        <div className="admin-table-wrap">
          <table className="admin-table audit-table">
            <thead>
              <tr><th>Time</th><th>Actor</th><th>Action</th><th>Resource</th><th>Details</th></tr>
            </thead>
            <tbody>
              {logs.map((log) => (
                <tr key={log.id}>
                  <td>{log.created_at}</td>
                  <td>{log.actor_email || "System"}</td>
                  <td><code>{log.action}</code></td>
                  <td>{log.resource_type} #{log.resource_id || "—"}</td>
                  <td><small>{String(log.metadata_json || "").slice(0, 120)}</small></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </>
  );
}

