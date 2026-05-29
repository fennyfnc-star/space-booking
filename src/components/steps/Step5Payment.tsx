import { useState, useEffect } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { createBooking, fetchPricing } from "@/utils/api";
import type { Package, Space, PackageThemeMetaField } from "@/types";

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
  } = useBookingStore();

  // Return label as-is from backend
  const enrichBreakdownLabel = (label: string): string => {
    return label;
  };

  const [loading, setLoading] = useState(false);
  const [checkingCart, setCheckingCart] = useState(true);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formStartedAt] = useState<number>(() => Math.floor(Date.now() / 1000));
  const [recaptchaWidgetId, setRecaptchaWidgetId] = useState<number | null>(null);
  const [packageQuestionAnswers, setPackageQuestionAnswers] = useState<
    Record<string, string | number | string[]>
  >({});
  const [packageOthersText, setPackageOthersText] = useState<Record<string, string>>({});

  const recaptchaConfig = window.sbConfig?.recaptcha;
  const recaptchaEnabled = !!recaptchaConfig?.enabled;
  const recaptchaVersion = recaptchaConfig?.version || "v3";
  const recaptchaSiteKey = recaptchaConfig?.siteKey || "";

  useEffect(() => {
    if (!recaptchaEnabled || recaptchaVersion !== "v2" || !recaptchaSiteKey) {
      return;
    }
    const grecaptcha = window.grecaptcha;
    if (!grecaptcha || recaptchaWidgetId !== null) {
      return;
    }
    grecaptcha.ready(() => {
      const container = document.getElementById("sb-recaptcha-v2");
      if (!container) return;
      const widgetId = grecaptcha.render(container, { sitekey: recaptchaSiteKey });
      setRecaptchaWidgetId(widgetId);
    });
  }, [recaptchaEnabled, recaptchaVersion, recaptchaSiteKey, recaptchaWidgetId]);

  const getRecaptchaToken = async (): Promise<string> => {
    if (!recaptchaEnabled) {
      return "";
    }
    if (!recaptchaSiteKey || !window.grecaptcha) {
      throw new Error("Captcha is not configured. Please contact admin.");
    }

    if (recaptchaVersion === "v2") {
      const token = window.grecaptcha.getResponse(recaptchaWidgetId ?? undefined);
      if (!token) {
        throw new Error("Please complete the captcha challenge.");
      }
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

  // Get first space ID from lockedResourceIds array
  const getFirstSpaceId = (): number => {
    const firstSpace = selectedItems.find((item) => item.type === "space");
    if (firstSpace) {
      return Number(firstSpace.id);
    }
    return 0;
  };

  // Refetch fresh pricing on Step6 mount (ensure up-to-date breakdown)
  useEffect(() => {
    const refreshPricing = async () => {
      const spaceId = getFirstSpaceId();
      if (!selectedItems.length || !selectedDate || !selectedStartTime || !selectedEndTime)
        return;

      const itemIds = selectedItems.map((item) => Number(item.id));
      
      const pricingParams = {
        space_id: spaceId,
        item_ids: itemIds,
        date: selectedDate,
        start_time: selectedStartTime,
        end_time: selectedEndTime,
        extras: selectedExtras,
        package_ids: useBookingStore.getState().getAllPackageIds(),
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
    const selectedItems = useBookingStore.getState().selectedItems;
    const packageItem = selectedItems.find((i) => i.type === "package") as Package | undefined;
    const spaceItem = selectedItems.find((i) => i.type === "space") as Space | undefined;
    
    // Get actual space ID - if package, get its primary space
    let spaceId = spaceItem?.id || 0;
    if (packageItem && !spaceId) {
      const pkg = packageItem as Package;
      spaceId = pkg.space_id || (pkg.space_ids?.[0]) || 0;
    }
    
    const packageId = packageItem?.id;
    const selectedItemIds = selectedItems.map((item) => item.id);

    if (!spaceId && !packageId) {
      setError("No space or package selected.");
      return;
    }

    const validationErrors: Record<string, string> = {};
    const customerName = String(customerInfo.name || "").trim();
    const customerEmail = String(customerInfo.email || "").trim();
    if (!customerName) {
      validationErrors.customer_name = "Full name is required.";
    }
    if (!customerEmail) {
      validationErrors.customer_email = "Email is required.";
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerEmail)) {
      validationErrors.customer_email = "Enter a valid email address.";
    }

    const packageFields = getSelectedPackageQuestionFields();
    packageFields.forEach((entry) => {
      const answerKey = packageFieldKey(entry.packageId, entry.field.key);
      const value = packageQuestionAnswers[answerKey];
      const isEmpty =
        value === undefined ||
        value === null ||
        value === "" ||
        (Array.isArray(value) && value.length === 0);
      if (entry.field.required && isEmpty) {
        validationErrors[answerKey] = "This field is required.";
      }

      const othersKey = packageOthersKey(entry.packageId, entry.field.key);
      const supportsOthers = !!entry.field.allow_others && ["radio", "checkbox", "select"].includes(entry.field.type);
      const othersSelected =
        supportsOthers &&
        ((Array.isArray(value) && value.includes("Others")) || value === "Others");
      if (othersSelected) {
        const othersValue = String(packageOthersText[othersKey] || "").trim();
        if (!othersValue) {
          validationErrors[othersKey] = "Please describe your \"Others\" answer.";
        }
      }
    });

    setFieldErrors(validationErrors);
    if (Object.keys(validationErrors).length > 0) {
      setError("Please complete all required fields before continuing.");
      return;
    }

    setLoading(true);
    setError("");

    try {
      const recaptchaToken = await getRecaptchaToken();
      const normalizedAnswers = buildPackageQuestionPayload();
      const res = await createBooking({
        space_ids: selectedItems
          .filter((item) => item.type === "space")
          .map((item) => Number(item.id)),
        package_ids: selectedItems
          .filter((item) => item.type === "package")
          .map((item) => Number(item.id)),
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
        package_question_answers: normalizedAnswers,
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

  const packageFieldKey = (packageId: number, fieldKey: string): string =>
    `pkg_${packageId}__${fieldKey}`;

  const packageOthersKey = (packageId: number, fieldKey: string): string =>
    `pkg_${packageId}__${fieldKey}__others`;

  const getSelectedPackageQuestionFields = (): Array<{
    packageId: number;
    packageTitle: string;
    field: PackageThemeMetaField;
  }> => {
    return selectedItems
      .filter((item): item is Package => item.type === "package")
      .flatMap((pkg) => {
        const fields = Array.isArray(pkg.theme_meta_fields) ? pkg.theme_meta_fields : [];
        return fields.map((field) => ({
          packageId: Number(pkg.id),
          packageTitle: pkg.title,
          field,
        }));
      });
  };

  const onQuestionChange = (
    key: string,
    value: string | number | string[],
  ) => {
    setPackageQuestionAnswers((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const onOthersChange = (key: string, value: string) => {
    setPackageOthersText((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const buildPackageQuestionPayload = (): Array<{
    package_id: number;
    field_key: string;
    field_label: string;
    field_type: PackageThemeMetaField["type"];
    value: string | number | string[];
    others_text?: string;
  }> => {
    const payload: Array<{
      package_id: number;
      field_key: string;
      field_label: string;
      field_type: PackageThemeMetaField["type"];
      value: string | number | string[];
      others_text?: string;
    }> = [];

    getSelectedPackageQuestionFields().forEach((entry) => {
      const answerKey = packageFieldKey(entry.packageId, entry.field.key);
      const value = packageQuestionAnswers[answerKey];
      const isEmpty =
        value === undefined ||
        value === null ||
        value === "" ||
        (Array.isArray(value) && value.length === 0);
      if (isEmpty) return;

      const normalized: {
        package_id: number;
        field_key: string;
        field_label: string;
        field_type: PackageThemeMetaField["type"];
        value: string | number | string[];
        others_text?: string;
      } = {
        package_id: entry.packageId,
        field_key: entry.field.key,
        field_label: entry.field.label,
        field_type: entry.field.type,
        value,
      };

      const supportsOthers = !!entry.field.allow_others && ["radio", "checkbox", "select"].includes(entry.field.type);
      const othersSelected =
        supportsOthers &&
        ((Array.isArray(value) && value.includes("Others")) || value === "Others");
      if (othersSelected) {
        const othersValue = String(
          packageOthersText[packageOthersKey(entry.packageId, entry.field.key)] || "",
        ).trim();
        if (othersValue) {
          normalized.others_text = othersValue;
        }
      }
      payload.push(normalized);
    });

    return payload;
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

  const getPackageSpaceIds = (pkg?: Package): number[] => {
    if (!pkg) return [];

    if (Array.isArray(pkg.space_ids) && pkg.space_ids.length > 0) {
      return pkg.space_ids.map((id) => Number(id)).filter((id) => id > 0);
    }

    if (pkg.space_id) {
      return [Number(pkg.space_id)];
    }

    return [];
  };

  const getSpaceSummaryLabel = (): string => {
    const packageItems = selectedItems.filter(
      (item) => item.type === "package",
    ) as Package[];
    const explicitSpaces = selectedItems.filter(
      (item) => item.type === "space",
    );
    const explicitSpaceIds = new Set(explicitSpaces.map((item) => Number(item.id)));
    const packageSpaceIds = new Set<number>();

    packageItems.forEach((pkg) => {
      getPackageSpaceIds(pkg).forEach((spaceId) => {
        packageSpaceIds.add(spaceId);
      });
    });

    const labels = explicitSpaces.map((item) => {
      const isPackageIncluded = packageSpaceIds.has(Number(item.id));
      return `${item.title}${isPackageIncluded ? " (Package)" : ""}`;
    });

    packageItems.forEach((pkg) => {
      const packageIds = getPackageSpaceIds(pkg);
      packageIds.forEach((spaceId, index) => {
        if (explicitSpaceIds.has(spaceId)) {
          return;
        }

        const title =
          index === 0
            ? pkg.space_name || `Space #${spaceId}`
            : `Space #${spaceId}`;
        const shouldTagAsPackage = explicitSpaces.length > 0 || index > 0;
        labels.push(`${title}${shouldTagAsPackage ? " (Package)" : ""}`);
      });
    });

    if (labels.length > 0) {
      return labels.join(", ");
    }

    const fallbackPackage = packageItems[0];
    if (fallbackPackage) {
      const packageIds = getPackageSpaceIds(fallbackPackage);
      if (packageIds.length > 0) {
        const primarySpaceId = packageIds[0];
        return fallbackPackage.space_name || `Space #${primarySpaceId}`;
      }
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
              <input
                className="sb-input"
                type="text"
                value={String(customerInfo.name || "")}
                onChange={(e) => setCustomerField("name", e.target.value)}
                style={{ width: "100%", marginTop: 6 }}
              />
              {fieldErrors.customer_name && <span className="sb-error">{fieldErrors.customer_name}</span>}
            </label>
          </div>
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Email Address <span style={{ color: "#d63638" }}>*</span>
              <input
                className="sb-input"
                type="email"
                value={String(customerInfo.email || "")}
                onChange={(e) => setCustomerField("email", e.target.value)}
                style={{ width: "100%", marginTop: 6 }}
              />
              {fieldErrors.customer_email && <span className="sb-error">{fieldErrors.customer_email}</span>}
            </label>
          </div>
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Phone
              <input
                className="sb-input"
                type="text"
                value={String(customerInfo.phone || "")}
                onChange={(e) => setCustomerField("phone", e.target.value)}
                style={{ width: "100%", marginTop: 6 }}
              />
            </label>
          </div>
          <div className="sb-summary-row" style={{ gridColumn: "1 / -1" }}>
            <label style={{ display: "block", width: "100%" }}>
              Notes
              <textarea
                className="sb-input"
                value={String(customerInfo.notes || "")}
                onChange={(e) => setCustomerField("notes", e.target.value)}
                style={{ width: "100%", marginTop: 6, minHeight: 90 }}
              />
            </label>
          </div>

          {getSelectedPackageQuestionFields().map((entry) => {
            const answerKey = packageFieldKey(entry.packageId, entry.field.key);
            const othersKey = packageOthersKey(entry.packageId, entry.field.key);
            const value = packageQuestionAnswers[answerKey];
            const fieldType = entry.field.type;
            const options = Array.isArray(entry.field.options) ? entry.field.options : [];
            const showChoice = ["radio", "checkbox", "select"].includes(fieldType);
            const othersEnabled = !!entry.field.allow_others && showChoice;
            const othersSelected =
              othersEnabled &&
              ((Array.isArray(value) && value.includes("Others")) || value === "Others");

            return (
              <div key={`${entry.packageId}-${entry.field.key}`} className="sb-summary-row" style={{ gridColumn: "1 / -1", display: "block" }}>
                <label style={{ display: "block", marginBottom: 6 }}>
                  {entry.field.label} {entry.field.required ? <span style={{ color: "#d63638" }}>*</span> : null}
                  <span style={{ marginLeft: 8, color: "#666", fontSize: 12 }}>
                    ({entry.packageTitle})
                  </span>
                </label>
                {fieldType === "text" && (
                  <input className="sb-input" type="text" value={String(value || "")} onChange={(e) => onQuestionChange(answerKey, e.target.value)} />
                )}
                {fieldType === "textarea" && (
                  <textarea className="sb-input" value={String(value || "")} onChange={(e) => onQuestionChange(answerKey, e.target.value)} style={{ minHeight: 90 }} />
                )}
                {fieldType === "number" && (
                  <input className="sb-input" type="number" value={String(value || "")} onChange={(e) => onQuestionChange(answerKey, e.target.value)} />
                )}
                {fieldType === "select" && (
                  <select className="sb-input" value={String(value || "")} onChange={(e) => onQuestionChange(answerKey, e.target.value)}>
                    <option value="">Select an option</option>
                    {options.map((opt) => (
                      <option key={opt} value={opt}>{opt}</option>
                    ))}
                    {othersEnabled && <option value="Others">Others</option>}
                  </select>
                )}
                {fieldType === "radio" && (
                  <div>
                    {options.map((opt) => (
                      <label key={opt} style={{ display: "block", marginBottom: 6 }}>
                        <input
                          type="radio"
                          name={answerKey}
                          checked={value === opt}
                          onChange={() => onQuestionChange(answerKey, opt)}
                        />{" "}
                        {opt}
                      </label>
                    ))}
                    {othersEnabled && (
                      <label style={{ display: "block", marginBottom: 6 }}>
                        <input
                          type="radio"
                          name={answerKey}
                          checked={value === "Others"}
                          onChange={() => onQuestionChange(answerKey, "Others")}
                        />{" "}
                        Others
                      </label>
                    )}
                  </div>
                )}
                {fieldType === "checkbox" && (
                  <div>
                    {options.map((opt) => {
                      const arr = Array.isArray(value) ? value : [];
                      const checked = arr.includes(opt);
                      return (
                        <label key={opt} style={{ display: "block", marginBottom: 6 }}>
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={(e) => {
                              const current = Array.isArray(value) ? value : [];
                              const next = e.target.checked
                                ? [...current, opt]
                                : current.filter((v) => v !== opt);
                              onQuestionChange(answerKey, next);
                            }}
                          />{" "}
                          {opt}
                        </label>
                      );
                    })}
                    {othersEnabled && (
                      <label style={{ display: "block", marginBottom: 6 }}>
                        <input
                          type="checkbox"
                          checked={Array.isArray(value) ? value.includes("Others") : false}
                          onChange={(e) => {
                            const current = Array.isArray(value) ? value : [];
                            const next = e.target.checked
                              ? [...current, "Others"]
                              : current.filter((v) => v !== "Others");
                            onQuestionChange(answerKey, next);
                          }}
                        />{" "}
                        Others
                      </label>
                    )}
                  </div>
                )}
                {othersSelected && (
                  <div style={{ marginTop: 8 }}>
                    <label style={{ display: "block", marginBottom: 4 }}>Please specify</label>
                    <textarea
                      className="sb-input"
                      value={String(packageOthersText[othersKey] || "")}
                      onChange={(e) => onOthersChange(othersKey, e.target.value)}
                      style={{ minHeight: 90 }}
                    />
                    {fieldErrors[othersKey] && <span className="sb-error">{fieldErrors[othersKey]}</span>}
                  </div>
                )}
                {fieldErrors[answerKey] && <span className="sb-error">{fieldErrors[answerKey]}</span>}
              </div>
            );
          })}

          <div className="sb-summary-row">
            <span>Space</span>
            <span>{getSpaceSummaryLabel()}</span>
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
            <li key={i} className={`sb-breakdown__item ${item.label.includes('(Package Inclusion)') ? 'package-inclusion' : ''}`}>
              <span>{enrichBreakdownLabel(item.label)}</span>
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
        {recaptchaEnabled && recaptchaVersion === "v2" && (
          <div style={{ margin: "12px 0" }}>
            <div id="sb-recaptcha-v2"></div>
          </div>
        )}
        <input
          type="text"
          name="website_url"
          value=""
          onChange={() => {}}
          autoComplete="off"
          tabIndex={-1}
          aria-hidden="true"
          style={{ position: "absolute", left: "-9999px", opacity: 0, pointerEvents: "none" }}
        />

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
