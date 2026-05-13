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
    packageCoverage,
  } = useBookingStore();

  // Get package title for breakdown enrichment
  const packageTitle = packageCoverage.length > 0 ? packageCoverage[0].packageTitle : null;

  // Get space name for breakdown enrichment
  const spaceName = (() => {
    const spaceItem = selectedItems.find((i) => i.type === "space");
    if (spaceItem) return spaceItem.title;
    const pkgItem = selectedItems.find((i) => i.type === "package") as any;
    if (pkgItem?.space_name) return pkgItem.space_name;
    return "";
  })();

  // Return label as-is from backend
  const enrichBreakdownLabel = (label: string): string => {
    return label;
  };

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const handleCheckout = async () => {
    console.log("SB_DEBUG Step5Checkout: ========== START ==========");

    const selectedItemIds = useBookingStore
      .getState()
      .selectedItems.map((item) => item.id);
    const spaceId = selectedItemIds[0] || 0; // lead space
    const packageId = useBookingStore
      .getState()
      .selectedItems.find((i) => i.type === "package")?.id;

    const currentSelectedExtras = useBookingStore.getState().selectedExtras;

    // DEBUG: Console logs for Step 5 - Pre-validation
    console.log("SB_DEBUG Step5Checkout: spaceId:", spaceId);
    console.log("SB_DEBUG Step5Checkout: packageId:", packageId);
    console.log(
      "SB_DEBUG Step5Checkout: selectedItemIds:",
      JSON.stringify(selectedItemIds),
    );
    console.log(
      "SB_DEBUG Step5Checkout: selectedItems:",
      JSON.stringify(useBookingStore.getState().selectedItems),
    );
    console.log(
      "SB_DEBUG Step5Checkout: selectedExtras:",
      JSON.stringify(currentSelectedExtras),
    );
    console.log("SB_DEBUG Step5Checkout: date:", selectedDate);
    console.log("SB_DEBUG Step5Checkout: startTime:", selectedStartTime);
    console.log("SB_DEBUG Step5Checkout: endTime:", selectedEndTime);
    console.log(
      "SB_DEBUG Step5Checkout: customerInfo:",
      JSON.stringify(customerInfo),
    );

    if (!spaceId) {
      console.log("SB_DEBUG Step5Checkout: ERROR - No space selected");
      setError("No space selected.");
      return;
    }

    console.log("SB_DEBUG Step5Checkout: Validation passed, proceeding...");

    setLoading(true);
    setError("");

    console.log("SB_DEBUG Step5Checkout: Calling createBooking API...");
    console.log("SB_DEBUG Step5Checkout: Request payload:", {
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

      console.log(
        "SB_DEBUG Step5Checkout: createBooking API response:",
        JSON.stringify(res),
      );

      setCheckoutData({
        checkoutUrl: res.checkout_url,
        bookingId: res.booking_id,
        totalPrice: res.total_price,
        breakdown: res.breakdown,
      });

      console.log("SB_DEBUG Step5Checkout: Checkout data set:", {
        checkoutUrl: res.checkout_url,
        bookingId: res.booking_id,
        totalPrice: res.total_price,
        breakdown: res.breakdown,
      });
      console.log("SB_DEBUG Step5Checkout: Redirecting to:", res.checkout_url);

      // Redirect to WooCommerce checkout
      window.location.href = res.checkout_url;
    } catch (e) {
      console.log("SB_DEBUG Step5Checkout: ERROR catch:", e);
      setError((e as Error).message);
    } finally {
      setLoading(false);
      console.log("SB_DEBUG Step5Checkout: ========== END ==========");
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
