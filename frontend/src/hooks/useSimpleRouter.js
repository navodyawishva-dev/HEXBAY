import { useEffect, useState } from "react";

export function useSimpleRouter() {
  const currentLocation = () => ({
    path: window.location.pathname || "/",
    search: window.location.search || "",
  });
  const [location, setLocation] = useState(currentLocation);

  useEffect(() => {
    const handlePopState = () => setLocation(currentLocation());
    window.addEventListener("popstate", handlePopState);
    return () => window.removeEventListener("popstate", handlePopState);
  }, []);

  const navigate = (nextPath) => {
    const nextUrl = new URL(nextPath, window.location.origin);
    if (
      nextUrl.pathname === location.path &&
      nextUrl.search === location.search
    ) {
      return;
    }
    window.history.pushState({}, "", nextPath);
    setLocation(currentLocation());
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  return { ...location, navigate };
}
