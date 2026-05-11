import React, { useState, useEffect } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { createBooking, checkCartHasBooking, fetchPricing } from "@/utils/api";
import type { Package } from "@/types";

export function Step6Payment() {
  const {
    checkoutUrl,
    lockedResourceIds,
    priceBreakdown,
    totalPrice,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    customerInfo,
    selectedExtras,
    availableExtras,
    prevStep,
    hasCartBooking,
    checkCartBooking,
    selectedItems,
  } = useBookingStore();

  const [loading, setLoading] = useState(false);
  const [checkingCart, setCheckingCart] = useState(true);
  const [error, setError] = useState("");

  // Get first space ID from lockedResourceIds array
  const getFirstSpaceId = (): number => {
    if (lockedResourceIds && lockedResourceIds.length > 0) {
      return lockedResourceIds[0];
    }
    if (selectedItems.length > 0) {
      return Number(selectedItems[0].id);
    }
    return 0;
  };

  // Refetch fresh pricing on Step6 mount (ensure up-to-date breakdown)
  useEffect(() => {
    const refreshPricing = async () => {
      const spaceId = getFirstSpaceId();
      if (!spaceId || !selectedDate || !selectedStartTime || !selectedEndTime)
        return;

      // Build item_ids from resolved space IDs (not package IDs)
      const itemIds: number[] = [];
      for (const item of selectedItems) {
        if (item.type === "space") {
          itemIds.push(Number(item.id));
        } else if (item.type === "package") {
          const pkg = item as Package;
          if (pkg.space_ids && Array.isArray(pkg.space_ids)) {
            itemIds.push(...pkg.space_ids);
          } else if (pkg.space_id) {
            itemIds.push(pkg.space_id);
          }
        }
      }
      
      const pricingParams = {
        space_id: spaceId,
        item_ids: itemIds,
        date: selectedDate,
        start_time: selectedStartTime,
        end_time: selectedEndTime,
        extras: selectedExtras,
        package_id: selectedItems.find((i) => i.type === "package")?.id,
      };

      try {
        const res = await fetchPricing(pricingParams);
        useBookingStore
          .getState()
          .setPriceBreakdown(res.breakdown, res.total_price);
      } catch (e) {
        console.error("Step6 pricing refresh failed:", e);
      }
    };

    refreshPricing();
  }, []); // Run once on mount

  const handlePayment = async () => {
    // If existing checkout or cart booking, redirect immediately
    if (checkoutUrl) {
      window.location.href = checkoutUrl;
      return;
    }

    if (hasCartBooking) {
      // Fetch fresh checkout URL since cart exists but no stored URL
      const freshCheckoutUrl = "/checkout/";
      window.location.href = freshCheckoutUrl;
      return;
    }

    // Normal flow: create new booking
    const selectedItemIds = useBookingStore
      .getState()
      .selectedItems.map((item) => item.id);
    const spaceId = selectedItemIds[0] || 0; // lead space
    const packageId = useBookingStore
      .getState()
      .selectedItems.find((i) => i.type === "package")?.id;

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
        extras: selectedExtras,
        price_breakdown: priceBreakdown,
      });

      useBookingStore.getState().setCheckoutData({
        checkoutUrl: res.checkout_url,
        bookingId: res.booking_id,
        totalPrice: res.total_price,
        breakdown: res.breakdown || priceBreakdown,
      });

      window.location.href = res.checkout_url;
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  };

  // Check cart on component mount
  useEffect(() => {
    const checkCart = async () => {
      if (!checkoutUrl && !hasCartBooking) {
        try {
          await checkCartBooking();
        } catch (e) {
          console.error("Cart check failed:", e);
        }
      }
      setCheckingCart(false);
    };

    checkCart();
  }, [checkoutUrl, hasCartBooking, checkCartBooking]);

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
    <div className="sb-step sb-step-6">
      <h2 className="sb-step__title">Complete Booking</h2>
      <div className="sb-checkout-summary">
        <h3>Final Review</h3>

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
          {/* <div className="sb-summary-row">
            <span>Name</span>
            <span>{String(customerInfo.name || "")}</span>
          </div>
          <div className="sb-summary-row">
            <span>Email</span>
            <span>{String(customerInfo.email || "")}</span>
          </div> */}
        </div>

        <h4>Price Breakdown</h4>
        <ul className="sb-breakdown">
          {priceBreakdown.map((item, i) => (
            <li key={i} className="sb-breakdown__item">
              <span>{item.label}</span>
              <span>
                {window.sbConfig.symbol}
                {item.amount.toFixed(2)}
              </span>
            </li>
          ))}
        </ul>
        <div className="sb-breakdown__total">
          Total:{" "}
          <strong>
            {window.sbConfig.symbol}
            {totalPrice.toFixed(2)}
          </strong>
        </div>

        {error && <div className="sb-error">{error}</div>}

        <div className="sb-step__actions">
          <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
            ← Back
          </button>
          <button
            className="sb-btn sb-btn--primary"
            onClick={handlePayment}
            disabled={loading || checkingCart}
          >
            {checkingCart
              ? "Checking cart..."
              : loading
                ? "Creating Booking..."
                : checkoutUrl || hasCartBooking
                  ? "Continue with Payment →"
                  : "Proceed to Secure Payment →"}
          </button>
        </div>

        {checkoutUrl && (
          <p className="sb-note">
            Redirecting to secure WooCommerce checkout...
          </p>
        )}
      </div>
    </div>
  );
}
