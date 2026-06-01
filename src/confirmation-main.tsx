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

  // Set store for confirmation
  const store = useBookingStore.getState();
  const newId = bookingId ? parseInt(bookingId) : null;
  if (newId !== store.bookingId) {
    useBookingStore.setState({
      bookingId: newId,
      currentStep: 6,
    });
    // Load status + populate store
    if (newId) {
      store.loadBookingStatus(newId);
    }
  }

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
