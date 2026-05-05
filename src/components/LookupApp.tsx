import React, { useEffect, useState } from "react";
import { sendMagicLink, fetchCustomerBookings } from "@/utils/api";
import type { CustomerBooking } from "@/types";

type ViewState = "form" | "sent" | "bookings" | "error";

export function LookupApp() {
  const [view, setView] = useState<ViewState>("form");
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [bookings, setBookings] = useState<CustomerBooking[]>([]);
  const [token, setToken] = useState("");

  // Auto-detect token from URL on mount
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const t = params.get("sb_token");
    if (t) {
      setToken(t);
      loadBookings(t);
    }
  }, []);

  const loadBookings = (t: string) => {
    setLoading(true);
    fetchCustomerBookings(t)
      .then((res) => {
        setBookings(res.bookings as CustomerBooking[]);
        setView("bookings");
      })
      .catch((e: Error) => {
        setError(e.message);
        setView("error");
      })
      .finally(() => setLoading(false));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      await sendMagicLink(email);
      setView("sent");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const statusBadge = (status: CustomerBooking["status"]) => {
    const map = {
      confirmed: "sb-badge--success",
      pending: "sb-badge--warning",
      cancelled: "sb-badge--danger",
      refunded: "sb-badge--info",
    };
    return <span className={`sb-badge ${map[status] ?? ""}`}>{status}</span>;
  };

  // ── Email form ──────────────────────────────────────────────────────────
  if (view === "form") {
    return (
      <div className="sb-lookup">
        <h2 className="sb-lookup__title">View Your Bookings</h2>
        <p className="sb-lookup__desc">
          Enter your email address and we'll send you a secure link to view your
          booking history. No password required.
        </p>

        <form className="sb-form" onSubmit={handleSubmit} noValidate>
          <div className="sb-field">
            <label className="sb-label" htmlFor="sb-lookup-email">
              Email Address
            </label>
            <input
              id="sb-lookup-email"
              type="email"
              className="sb-input"
              required
              value={email}
              autoComplete="email"
              onChange={(e) => setEmail(e.target.value)}
            />
          </div>
          {error && <div className="sb-error">{error}</div>}
          <button
            type="submit"
            className="sb-btn sb-btn--primary"
            disabled={loading || !email}
          >
            {loading ? "Sending…" : "Send My Booking Link →"}
          </button>
        </form>
      </div>
    );
  }

  // ── Link sent ────────────────────────────────────────────────────────────
  if (view === "sent") {
    return (
      <div className="sb-lookup sb-lookup--sent">
        <span className="sb-confirm-icon" aria-hidden="true">
          📧
        </span>
        <h2>Check Your Email</h2>
        <p>
          If we found bookings for <strong>{email}</strong>, we've sent a secure
          link valid for <strong>30 minutes</strong>.
        </p>
        <button
          className="sb-btn sb-btn--ghost"
          onClick={() => setView("form")}
        >
          Try a different email
        </button>
      </div>
    );
  }

  // ── Error ────────────────────────────────────────────────────────────────
  if (view === "error") {
    return (
      <div className="sb-lookup sb-lookup--error">
        <h2>Link Invalid or Expired</h2>
        <p>{error}</p>
        <button
          className="sb-btn sb-btn--primary"
          onClick={() => setView("form")}
        >
          Request a new link
        </button>
      </div>
    );
  }

  // ── Bookings list ────────────────────────────────────────────────────────
  return (
    <div className="sb-lookup sb-lookup--list">
      <h2 className="sb-lookup__title">Your Bookings</h2>

      {loading && <div className="sb-loading">Loading…</div>}

      {bookings.length === 0 && !loading && (
        <p className="sb-empty">No bookings found.</p>
      )}

      {bookings.map((b) => (
        <div key={b.id} className="sb-booking-card">
          <div className="sb-booking-card__header">
            <div>
              <h3 className="sb-booking-card__space">{b.space_name}</h3>
              <p className="sb-booking-card__date">
                {b.booking_date} · {b.start_time.slice(0, 5)} –{" "}
                {b.end_time.slice(0, 5)}
              </p>
            </div>
            {statusBadge(b.status)}
          </div>

          {b.thumbnail && (
            <img
              src={b.thumbnail}
              alt={b.space_name}
              className="sb-booking-card__img"
            />
          )}

          <div className="sb-booking-card__details">
            <span>
              Total: <strong>${Number(b.total_price).toFixed(2)}</strong>
            </span>
            {(b.extras || []).length > 0 && (
              <ul className="sb-confirm-extras">
                {(b.extras || []).map((ex: any, i: number) => (
                  <li key={i}>
                    {ex.extra_name} × {ex.quantity}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
