import { useState, useEffect } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { createBooking, fetchPricing } from "@/utils/api";
import type { Package, Space, SelectionItem } from "@/types";

type SelectedPackageItem = Extract<SelectionItem, { type: "package" }>;

export function Step5Payment() {
  const {
    checkoutUrl,
    priceBreakdown,
    totalPrice,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    customerInfo,
    selectedExtras,
    prevStep,
    hasCartBooking,
    checkCartBooking,
    selectedItems,
    setCustomerField,
    packageQuestionAnswers,
  } = useBookingStore();

  const [loading, setLoading] = useState(false);
  const [checkingCart, setCheckingCart] = useState(true);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formStartedAt] = useState<number>(() => Math.floor(Date.now() / 1000));
  const [recaptchaWidgetId, setRecaptchaWidgetId] = useState<number | null>(null);

  const recaptchaConfig = window.sbConfig?.recaptcha;
  const recaptchaEnabled = !!recaptchaConfig?.enabled;
  const recaptchaVersion = recaptchaConfig?.version || "v3";
  const recaptchaSiteKey = recaptchaConfig?.siteKey || "";
  const recaptchaConfigured = !!recaptchaConfig?.hasKeys && recaptchaSiteKey !== "";
  const recaptchaWarning = recaptchaEnabled && !recaptchaConfigured;
  const recaptchaProtectionActive = recaptchaEnabled && recaptchaConfigured;

  useEffect(() => {
    if (!recaptchaProtectionActive || recaptchaVersion !== "v2") return;
    const grecaptcha = window.grecaptcha;
    if (!grecaptcha || recaptchaWidgetId !== null) return;
    grecaptcha.ready(() => {
      const container = document.getElementById("sb-recaptcha-v2");
      if (!container) return;
      const widgetId = grecaptcha.render(container, { sitekey: recaptchaSiteKey });
      setRecaptchaWidgetId(widgetId);
    });
  }, [recaptchaProtectionActive, recaptchaVersion, recaptchaSiteKey, recaptchaWidgetId]);

  const getRecaptchaToken = async (): Promise<string> => {
    if (!recaptchaProtectionActive) return "";
    if (!recaptchaSiteKey || !window.grecaptcha) {
      throw new Error("Captcha is not configured. Please contact admin.");
    }
    if (recaptchaVersion === "v2") {
      const token = window.grecaptcha.getResponse(recaptchaWidgetId ?? undefined);
      if (!token) throw new Error("Please complete the captcha challenge.");
      return token;
    }
    return new Promise<string>((resolve, reject) => {
      window.grecaptcha?.ready(() => {
        window.grecaptcha
          ?.execute(recaptchaSiteKey, { action: "space_booking_submit" })
          .then(resolve)
          .catch(() => reject(new Error("Captcha token generation failed.")));
      });
    });
  };

  const getFirstSpaceId = (): number => {
    const firstSpace = selectedItems.find((item) => item.type === "space");
    return firstSpace ? Number(firstSpace.id) : 0;
  };

  useEffect(() => {
    const refreshPricing = async () => {
      const spaceId = getFirstSpaceId();
      if (!selectedItems.length || !selectedDate || !selectedStartTime || !selectedEndTime) return;
      const itemIds = selectedItems.map((item) => Number(item.id));
      try {
        const res = await fetchPricing({
          space_id: spaceId,
          item_ids: itemIds,
          date: selectedDate,
          start_time: selectedStartTime,
          end_time: selectedEndTime,
          extras: selectedExtras,
          package_ids: useBookingStore.getState().getAllPackageIds(),
        });
        useBookingStore.getState().setPriceBreakdown(res.breakdown, res.total_price);
      } catch (e) {
        console.error("Step5 pricing refresh failed:", e);
      }
    };
    refreshPricing();
  }, []);

  const buildPackageQuestionPayload = () => {
    const payload: Array<{
      package_id: number;
      field_key: string;
      field_label: string;
      field_type: "text" | "textarea" | "number" | "radio" | "checkbox" | "select";
      value: string | number | string[];
      others_text?: string;
    }> = [];

    const packageItems = selectedItems.filter(
      (item): item is SelectedPackageItem => item.type === "package",
    );
    packageItems.forEach((pkg) => {
      const fields = Array.isArray(pkg.theme_meta_fields) ? pkg.theme_meta_fields : [];
      fields.forEach((field) => {
        const answerKey = `pkg_${pkg.id}__${field.key}`;
        const answer = packageQuestionAnswers[answerKey];
        if (!answer) return;
        const value = answer.value;
        const isEmpty =
          value === undefined ||
          value === null ||
          value === "" ||
          (Array.isArray(value) && value.length === 0);
        if (isEmpty) return;

        payload.push({
          package_id: Number(pkg.id),
          field_key: field.key,
          field_label: field.label,
          field_type: field.type,
          value,
          ...(answer.others_text ? { others_text: answer.others_text } : {}),
        });
      });
    });

    return payload;
  };

  const packageQuestionReviewRows = (() => {
    const rows: Array<{ label: string; value: string }> = [];
    const packageItems = selectedItems.filter(
      (item): item is SelectedPackageItem => item.type === "package",
    );
    packageItems.forEach((pkg) => {
      const fields = Array.isArray(pkg.theme_meta_fields) ? pkg.theme_meta_fields : [];
      fields.forEach((field) => {
        const answerKey = `pkg_${pkg.id}__${field.key}`;
        const answer = packageQuestionAnswers[answerKey];
        if (!answer) return;
        const rawValue = answer.value;
        const isEmpty =
          rawValue === undefined ||
          rawValue === null ||
          rawValue === "" ||
          (Array.isArray(rawValue) && rawValue.length === 0);
        if (isEmpty) return;
        const renderedValue = Array.isArray(rawValue) ? rawValue.join(", ") : String(rawValue);
        const suffix = answer.others_text ? ` | Others: ${String(answer.others_text)}` : "";
        rows.push({
          label: `${field.label} (${pkg.title})`,
          value: `${renderedValue}${suffix}`,
        });
      });
    });
    return rows;
  })();

  const handlePayment = async () => {
    if (checkoutUrl) {
      window.location.href = checkoutUrl;
      return;
    }
    if (hasCartBooking) {
      window.location.href = "/checkout/";
      return;
    }

    const packageItem = selectedItems.find((i) => i.type === "package") as Package | undefined;
    const spaceItem = selectedItems.find((i) => i.type === "space") as Space | undefined;
    let spaceId = spaceItem?.id || 0;
    if (packageItem && !spaceId) {
      spaceId = packageItem.space_id || packageItem.space_ids?.[0] || 0;
    }
    const packageId = packageItem?.id;
    const selectedItemIds = selectedItems.map((item) => item.id);

    const validationErrors: Record<string, string> = {};
    const customerName = String(customerInfo.name || "").trim();
    const customerEmail = String(customerInfo.email || "").trim();
    if (!customerName) validationErrors.customer_name = "Full name is required.";
    if (!customerEmail) validationErrors.customer_email = "Email is required.";
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerEmail)) validationErrors.customer_email = "Enter a valid email address.";
    setFieldErrors(validationErrors);
    if (Object.keys(validationErrors).length > 0) {
      setError("Please complete all required fields before continuing.");
      return;
    }

    if (!spaceId && !packageId) {
      setError("No space or package selected.");
      return;
    }

    setLoading(true);
    setError("");

    try {
      const recaptchaToken = await getRecaptchaToken();
      const res = await createBooking({
        space_ids: selectedItems.filter((item) => item.type === "space").map((item) => Number(item.id)),
        package_ids: selectedItems.filter((item) => item.type === "package").map((item) => Number(item.id)),
        selected_item_ids: selectedItemIds,
        date: selectedDate,
        start_time: selectedStartTime,
        end_time: selectedEndTime,
        customer_name: customerName,
        customer_email: customerEmail,
        customer_phone: String(customerInfo.phone || ""),
        notes: String(customerInfo.notes || ""),
        website_url: "",
        form_started_at: formStartedAt,
        recaptcha_token: recaptchaToken,
        extras: selectedExtras,
        price_breakdown: priceBreakdown,
        package_question_answers: buildPackageQuestionPayload(),
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

  const formatTimeTo12Hour = (timeStr: string): string => {
    const [hourStr, minuteStr] = timeStr.split(":");
    let hour = parseInt(hourStr, 10);
    const minutes = minuteStr;
    const period = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minutes} ${period}`;
  };

  const getPackageSpaceIds = (pkg?: Package): number[] => {
    if (!pkg) return [];
    if (Array.isArray(pkg.space_ids) && pkg.space_ids.length > 0) {
      return pkg.space_ids.map((id) => Number(id)).filter((id) => id > 0);
    }
    if (pkg.space_id) return [Number(pkg.space_id)];
    return [];
  };

  const getSpaceSummaryLabel = (): string => {
    const packageItems = selectedItems.filter((item) => item.type === "package") as Package[];
    const explicitSpaces = selectedItems.filter((item) => item.type === "space");
    const explicitSpaceIds = new Set(explicitSpaces.map((item) => Number(item.id)));
    const packageSpaceIds = new Set<number>();
    packageItems.forEach((pkg) => getPackageSpaceIds(pkg).forEach((spaceId) => packageSpaceIds.add(spaceId)));

    const labels = explicitSpaces.map((item) => {
      const isPackageIncluded = packageSpaceIds.has(Number(item.id));
      return `${item.title}${isPackageIncluded ? " (Package)" : ""}`;
    });

    packageItems.forEach((pkg) => {
      const packageIds = getPackageSpaceIds(pkg);
      packageIds.forEach((spaceId, index) => {
        if (explicitSpaceIds.has(spaceId)) return;
        const title = index === 0 ? pkg.space_name || `Space #${spaceId}` : `Space #${spaceId}`;
        const shouldTagAsPackage = explicitSpaces.length > 0 || index > 0;
        labels.push(`${title}${shouldTagAsPackage ? " (Package)" : ""}`);
      });
    });

    if (labels.length > 0) return labels.join(", ");
    const fallbackPackage = packageItems[0];
    if (fallbackPackage) {
      const packageIds = getPackageSpaceIds(fallbackPackage);
      if (packageIds.length > 0) return fallbackPackage.space_name || `Space #${packageIds[0]}`;
      return fallbackPackage.title || "No space selected";
    }
    return "No space selected";
  };

  return (
    <div className="sb-step sb-step-5">
      <h2 className="sb-step__title">Complete Booking</h2>
      <div className="sb-checkout-summary">
        <h3>Final Review</h3>
        <div className="sb-summary-grid">
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Full Name <span style={{ color: "#d63638" }}>*</span>
              <input className="sb-input" type="text" value={String(customerInfo.name || "")} onChange={(e) => setCustomerField("name", e.target.value)} style={{ width: "100%", marginTop: 6 }} />
              {fieldErrors.customer_name && <span className="sb-error">{fieldErrors.customer_name}</span>}
            </label>
          </div>
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Email Address <span style={{ color: "#d63638" }}>*</span>
              <input className="sb-input" type="email" value={String(customerInfo.email || "")} onChange={(e) => setCustomerField("email", e.target.value)} style={{ width: "100%", marginTop: 6 }} />
              {fieldErrors.customer_email && <span className="sb-error">{fieldErrors.customer_email}</span>}
            </label>
          </div>
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Phone
              <input className="sb-input" type="text" value={String(customerInfo.phone || "")} onChange={(e) => setCustomerField("phone", e.target.value)} style={{ width: "100%", marginTop: 6 }} />
            </label>
          </div>
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Notes
              <textarea className="sb-input" value={String(customerInfo.notes || "")} onChange={(e) => setCustomerField("notes", e.target.value)} style={{ width: "100%", marginTop: 6, minHeight: 90 }} />
            </label>
          </div>
          <div className="sb-summary-row"><span>Space</span><span>{getSpaceSummaryLabel()}</span></div>
          <div className="sb-summary-row"><span>Date</span><span>{selectedDate}</span></div>
          <div className="sb-summary-row"><span>Time</span><span>{formatTimeTo12Hour(selectedStartTime)} – {formatTimeTo12Hour(selectedEndTime)}</span></div>
        </div>

        {packageQuestionReviewRows.length > 0 && (
          <>
            <h4>Package Answers</h4>
            <ul className="sb-breakdown">
              {packageQuestionReviewRows.map((row, i) => (
                <li key={`${row.label}-${i}`} className="sb-breakdown__item">
                  <span>{row.label}</span>
                  <span>{row.value}</span>
                </li>
              ))}
            </ul>
          </>
        )}

        <h4>Price Breakdown</h4>
        <ul className="sb-breakdown">
          {priceBreakdown.map((item, i) => (
            <li key={i} className={`sb-breakdown__item ${item.label.includes("(Package Inclusion)") ? "package-inclusion" : ""}`}>
              <span>{item.label}</span>
              <span>{window.sbConfig.symbol}{item.amount.toFixed(2)}</span>
            </li>
          ))}
        </ul>
        <div className="sb-breakdown__total">Total: <strong>{window.sbConfig.symbol}{totalPrice.toFixed(2)}</strong></div>
        {error && <div className="sb-error">{error}</div>}
        {recaptchaWarning && (
          <div className="sb-note sb-note--warning">
            Booking is unprotected because WooCommerce reCAPTCHA is not configured. Your submission will still be accepted.
          </div>
        )}
        {recaptchaProtectionActive && recaptchaVersion === "v2" && (
          <div style={{ margin: "12px 0" }}><div id="sb-recaptcha-v2"></div></div>
        )}
        <input type="text" name="website_url" value="" onChange={() => {}} autoComplete="off" tabIndex={-1} aria-hidden="true" style={{ position: "absolute", left: "-9999px", opacity: 0, pointerEvents: "none" }} />
        <div className="sb-step__actions">
          <button className="sb-btn sb-btn--ghost" onClick={prevStep}>← Back</button>
          <button className="sb-btn sb-btn--primary" onClick={handlePayment} disabled={loading || checkingCart}>
            {checkingCart ? "Checking cart..." : loading ? "Creating Booking..." : checkoutUrl || hasCartBooking ? "Continue with Payment →" : "Proceed to Secure Payment →"}
          </button>
        </div>
      </div>
    </div>
  );
}
