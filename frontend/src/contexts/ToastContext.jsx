import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { createPortal } from "react-dom";

const ToastContext = createContext(null);
let nextToastId = 1;

function ToastItem({ toast, dismiss }) {
  const { id, message, type, duration, actionLabel, onAction } = toast;

  useEffect(() => {
    if (!duration) return undefined;
    const timeout = window.setTimeout(() => dismiss(id), duration);
    return () => window.clearTimeout(timeout);
  }, [dismiss, duration, id]);

  return (
    <div className={`toast toast-${type}`} role={type === "error" ? "alert" : "status"}>
      <span className="toast-indicator" aria-hidden="true" />
      <p>{message}</p>
      {actionLabel && onAction && (
        <button
          type="button"
          className="toast-action"
          onClick={() => {
            onAction();
            dismiss(id);
          }}
        >
          {actionLabel}
        </button>
      )}
      <button
        type="button"
        className="toast-close"
        aria-label="Dismiss notification"
        onClick={() => dismiss(id)}
      >
        ×
      </button>
    </div>
  );
}

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const dismiss = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);
  const showToast = useCallback((message, options = {}) => {
    if (!message) return null;
    const toast = {
      id: nextToastId++,
      message,
      type: options.type ?? "info",
      duration: options.duration ?? 4200,
      actionLabel: options.actionLabel,
      onAction: options.onAction,
    };
    setToasts((current) => [...current.slice(-3), toast]);
    return toast.id;
  }, []);
  const value = useMemo(() => ({ showToast, dismissToast: dismiss }), [dismiss, showToast]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      {createPortal(
        <div className="toast-region" aria-live="polite" aria-atomic="false">
          {toasts.map((toast) => (
            <ToastItem key={toast.id} toast={toast} dismiss={dismiss} />
          ))}
        </div>,
        document.body,
      )}
    </ToastContext.Provider>
  );
}

export function useToast() {
  const context = useContext(ToastContext);
  if (!context) throw new Error("useToast must be used inside ToastProvider.");
  return context;
}
