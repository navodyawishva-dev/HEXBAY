const localApiUrl = `${window.location.protocol}//${window.location.hostname}:8080/api/v1`;
export const API_URL = import.meta.env.VITE_API_URL ?? localApiUrl;

export async function apiRequest(path, { method = "GET", body, token } = {}) {
  const response = await fetch(`${API_URL}${path}`, {
    method,
    headers: {
      Accept: "application/json",
      ...(body ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });

  const payload = await response.json().catch(() => ({
    success: false,
    message: "The server returned an unreadable response.",
    data: null,
    errors: null,
  }));

  if (!response.ok) {
    const error = new Error(payload.message || "Request failed.");
    error.status = response.status;
    error.validationErrors = payload.errors;
    throw error;
  }

  return payload;
}

export async function apiUpload(path, { formData, token }) {
  const response = await fetch(`${API_URL}${path}`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: formData,
  });
  const payload = await response.json().catch(() => ({
    success: false,
    message: "The server returned an unreadable upload response.",
    data: null,
    errors: null,
  }));
  if (!response.ok) {
    const error = new Error(payload.message || "Upload failed.");
    error.status = response.status;
    error.validationErrors = payload.errors;
    throw error;
  }
  return payload;
}

export async function apiDownload(path, { token, fallbackName = "download" }) {
  const response = await fetch(`${API_URL}${path}`, {
    headers: {
      Accept: "*/*",
      Authorization: `Bearer ${token}`,
    },
  });
  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    throw new Error(payload?.message || "Download failed.");
  }
  const disposition = response.headers.get("content-disposition") ?? "";
  const match = disposition.match(/filename="([^"]+)"/i);
  const filename = match?.[1] ?? fallbackName;
  const url = URL.createObjectURL(await response.blob());
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export function mediaUrl(kind, storedFilename) {
  const storageToken = storedFilename?.split(".")[0] ?? "";
  return storedFilename
    ? `${API_URL}/media/${kind}/${encodeURIComponent(storageToken)}`
    : "";
}
