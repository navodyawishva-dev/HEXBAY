import { createContext, useContext, useEffect, useMemo, useState } from "react";
import { apiRequest } from "../api/client";

const STORAGE_KEY = "hexbay_access_token";
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [token, setToken] = useState(() => {
    // Access tokens survive refreshes in this tab, but are not persisted after
    // the browser session ends. Remove any token stored by older builds.
    localStorage.removeItem(STORAGE_KEY);
    return sessionStorage.getItem(STORAGE_KEY);
  });
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(Boolean(token));

  useEffect(() => {
    if (!token) {
      setUser(null);
      setLoading(false);
      return;
    }

    let active = true;
    setLoading(true);
    apiRequest("/auth/me", { token })
      .then((payload) => {
        if (active) setUser(payload.data.user);
      })
      .catch(() => {
        if (active) {
          sessionStorage.removeItem(STORAGE_KEY);
          setToken(null);
          setUser(null);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [token]);

  const acceptAuthentication = (payload) => {
    sessionStorage.setItem(STORAGE_KEY, payload.access_token);
    setToken(payload.access_token);
    setUser(payload.user);
  };

  const login = async (credentials) => {
    const response = await apiRequest("/auth/login", {
      method: "POST",
      body: credentials,
    });
    acceptAuthentication(response.data);
    return response.data.user;
  };

  const register = async (role, details) => {
    const endpoint =
      role === "shop_owner" ? "/auth/register/vendor" : "/auth/register/customer";
    const response = await apiRequest(endpoint, {
      method: "POST",
      body: details,
    });
    acceptAuthentication(response.data);
    return response.data.user;
  };

  const refreshUser = async () => {
    if (!token) return null;
    const response = await apiRequest("/auth/me", { token });
    setUser(response.data.user);
    return response.data.user;
  };

  const logout = async () => {
    try {
      if (token) {
        await apiRequest("/auth/logout", { method: "POST", token });
      }
    } finally {
      sessionStorage.removeItem(STORAGE_KEY);
      setToken(null);
      setUser(null);
    }
  };

  const value = useMemo(
    () => ({ token, user, loading, login, register, logout, refreshUser }),
    [token, user, loading],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used inside AuthProvider.");
  return context;
}
