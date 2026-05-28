import React from "react";
import { createRoot } from "react-dom/client";
import { BookingApp } from "./components/BookingApp";
import { useBookingStore } from "./store/bookingStore";

const initConfirmation = () => {
  const container = document.getElementById("sb-confirmation-app")!;
  if (!container) return;

  // Clear loading
  container.innerHTML = '<div id="sb-booking-app"></div>';

  const bookingApp = document.getElementById("sb-booking-app")!;
  const bookingId =
    container.dataset.bookingId ||
    new URLSearchParams(window.location.search).get("id") ||
    null;
  const status =
    container.dataset.status ||
    new URLSearchParams(window.location.search).get("status") ||
    "pending";

  // Set store for confirmation
  const store = useBookingStore.getState();
  const newId = bookingId ? parseInt(bookingId) : null;
  if (newId !== store.bookingId) {
    useBookingStore.setState({
      bookingId: newId,
      currentStep: 7,
    });
    // Load status + populate store
    if (newId) {
      store.loadBookingStatus(newId).then(() => {
        // Reset persisted booking state after loading confirmed booking
        if (useBookingStore.getState().bookingStatus === "confirmed") {
          useBookingStore.getState().clearPersistedState();
        }
      });
    }
  }

  // Update URL for Step7 status detection
  const url = new URL(window.location.href);
  if (status === "confirmed") {
    url.searchParams.set("status", "confirmed");
  }
  window.history.replaceState({}, "", url.toString());

  // Render
  const root = createRoot(bookingApp);
  root.render(<BookingApp />);
};

// Init
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initConfirmation);
} else {
  initConfirmation();
}
