import React, { useState } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { createBooking } from "@/utils/api";

export function Step5Checkout() {
  const {
    checkoutUrl,
    priceBreakdown,
    totalPrice,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    customerInfo,
    setCheckoutData,
    prevStep,
    selectedItems,
  } = useBookingStore();

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const handleCheckout = async () => {
    const selectedItemIds = useBookingStore
      .getState()
      .selectedItems.map((item) => item.id);
    const spaceId = selectedItemIds[0] || 0; // lead space
    const packageId = useBookingStore
      .getState()
      .selectedItems.find((i) => i.type === "package")?.id;

    const currentSelectedExtras = useBookingStore.getState().selectedExtras;
    console.log(
      "SB_DEBUG Step5Checkout: selectedExtras being sent:",
      JSON.stringify(currentSelectedExtras),
    );
    console.log(
      "SB_DEBUG Step5Checkout: selectedItems:",
      JSON.stringify(selectedItemIds),
    );

    if (!spaceId) {
      setError("No space selected.");
      return;
    }

    setLoading(true);
    setError("");

    try {
      const res = await createBooking({
        space_id: spaceId,
        package_id: packageId,
        selected_item_ids: selectedItemIds,
        date: selectedDate,
        start_time: selectedStartTime,
        end_time: selectedEndTime,
        customer_name: String(customerInfo.name || ""),
        customer_email: String(customerInfo.email || ""),
        customer_phone: String(customerInfo.phone || ""),
        notes: String(customerInfo.notes || ""),
        extras: currentSelectedExtras,
      });

      setCheckoutData({
        checkoutUrl: res.checkout_url,
        bookingId: res.booking_id,
        totalPrice: res.total_price,
        breakdown: res.breakdown,
      });

      // Redirect to WooCommerce checkout
      window.location.href = res.checkout_url;
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="sb-step sb-step-5">
      <h2 className="sb-step__title">Review & Checkout</h2>
      <div className="sb-checkout-summary">
        <h3>Booking Summary</h3>

        <div className="sb-summary-grid">
          <div className="sb-summary-row">
            <span>Space</span>
            <span>{selectedItems[0]?.title ?? "Multiple Items"}</span>
          </div>
          <div className="sb-summary-row">
            <span>Date</span>
            <span>{selectedDate}</span>
          </div>
          <div className="sb-summary-row">
            <span>Time</span>
            <span>
              {selectedStartTime} – {selectedEndTime}
            </span>
          </div>
          <div className="sb-summary-row">
            <span>Name</span>
            <span>{String(customerInfo.name || "")}</span>
          </div>
          <div className="sb-summary-row">
            <span>Email</span>
            <span>{String(customerInfo.email || "")}</span>
          </div>
        </div>

        <h4>Price Breakdown</h4>
        <ul className="sb-breakdown">
          {priceBreakdown.map((item, i) => (
            <li key={i} className="sb-breakdown__item">
              <span>{item.label}</span>
              <span>
                {item.amount.toFixed(2)} {window.sbConfig.symbol}
              </span>
            </li>
          ))}
        </ul>
        <div className="sb-breakdown__total">
          Total:{" "}
          <strong>
            {totalPrice.toFixed(2)} {window.sbConfig.symbol}
          </strong>
        </div>

        {error && <div className="sb-error">{error}</div>}

        <div className="sb-step__actions">
          <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
            ← Back
          </button>
          <button
            className="sb-btn sb-btn--primary"
            onClick={handleCheckout}
            disabled={loading || !!checkoutUrl}
          >
            {loading
              ? "Creating Booking..."
              : checkoutUrl
                ? "Redirecting..."
                : "Checkout Securely with WooCommerce →"}
          </button>
        </div>

        {checkoutUrl && (
          <p className="sb-note">Redirecting to secure checkout...</p>
        )}
      </div>
    </div>
  );
}
