import { useState } from "react";
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

  // Return label as-is from backend
  const enrichBreakdownLabel = (label: string): string => {
    return label;
  };

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const handleCheckout = async () => {
    const state = useBookingStore.getState();
    const selectedItemIds = state.selectedItems.map((item) => item.id);
    const spaceIds = state.selectedItems
      .filter((item) => item.type === "space")
      .map((item) => Number(item.id));
    const packageIds = state.selectedItems
      .filter((item) => item.type === "package")
      .map((item) => Number(item.id));
    const currentSelectedExtras = state.selectedExtras;

    if (spaceIds.length === 0 && packageIds.length === 0) {
      setError("No space or package selected.");
      return;
    }

    setLoading(true);
    setError("");

    try {
      const res = await createBooking({
        space_ids: spaceIds,
        package_ids: packageIds,
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

  // Format time to 12-hour format
  const formatTimeTo12Hour = (timeStr: string): string => {
    const [hourStr, minuteStr] = timeStr.split(":");
    let hour = parseInt(hourStr, 10);
    const minutes = minuteStr;
    const period = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minutes} ${period}`;
  };

  return (
    <div className="sb-step sb-step-5">
      <h2 className="sb-step__title">Review & Checkout</h2>
      <div className="sb-checkout-summary">
        <h3>Booking Summary</h3>

        <div className="sb-summary-grid">
          <div className="sb-summary-row">
            <span>Space</span>
            <span>
              {(() => {
                const spaceTitles = selectedItems
                  .filter((item) => item.type === "space")
                  .map((item) => item.title);
                return spaceTitles.length > 0
                  ? spaceTitles.join(", ")
                  : (selectedItems[0]?.title ?? "No space selected");
              })()}
            </span>
          </div>
          <div className="sb-summary-row">
            <span>Date</span>
            <span>{selectedDate}</span>
          </div>
          <div className="sb-summary-row">
            <span>Time</span>
            <span>
              {formatTimeTo12Hour(selectedStartTime)} –{" "}
              {formatTimeTo12Hour(selectedEndTime)}
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
              <span>{enrichBreakdownLabel(item.label)}</span>
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
