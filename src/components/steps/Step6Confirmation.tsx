import { useEffect, useState } from "react";
import { useBookingStore } from "@/store/bookingStore";

interface SelectedItem {
  id: number;
  type: string;
  title: string;
}

interface ExtraDetail {
  extra_id: number;
  extra_name?: string;
  title?: string;
  quantity: number;
  unit_price: number;
}

interface PackageInclusion {
  type: string;
  title: string;
  label?: string;
}

interface ExtraCatalogItem {
  id: number;
  title: string;
}

interface PriceBreakdownItem {
  label: string;
  amount: number;
  context?: {
    type: "space" | "package" | "extra" | "segment" | "modifier";
    name?: string;
    id?: number;
  };
}

interface BookingData {
  id: number;
  status: string;
  space_id: number;
  _space_title?: string;
  _space_titles?: string[];
  _selected_items?: SelectedItem[];
  package_id?: number;
  package_ids?: number[];
  customer_name: string;
  customer_email: string;
  customer_phone?: string;
  booking_date: string;
  start_time: string;
  end_time: string;
  duration_hours: number;
  total_price: number;
  extras?:
    | ExtraDetail[]
    | Array<{
        extra_id: number;
        quantity: number;
        extra_name?: string;
        title?: string;
      }>;
  _extras?:
    | ExtraDetail[]
    | Array<{ extra_id: number; quantity: number; title?: string }>;
  _extras_details?: ExtraDetail[];
  _price_breakdown?: PriceBreakdownItem[];
  _package_inclusions?: Array<{ type: string; title: string; label?: string }>;
  _meta_data?: Record<string, string>;
  notes?: string;
}

export function Step6Confirmation() {
  const bookingStatus = useBookingStore((s) => s.bookingStatus);
  const bookingId = useBookingStore((s) => s.bookingId);
  const reset = useBookingStore((s) => s.reset);
  const [bookingData, setBookingData] = useState<BookingData | null>(null);
  const [packageData, setPackageData] = useState<any>(null); // Store for package details if booking includes a package
  const [allExtras, setAllExtras] = useState<ExtraCatalogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  // Fetch full booking data when bookingId is available
  useEffect(() => {
    if (bookingId) {
      fetch(`${window.sbConfig.apiBase}/bookings/${bookingId}`)
        .then((res) => {
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          return res.json();
        })
        .then(async (data) => {
          setBookingData(data);

          // If booking includes package(s), fetch first package details for display fallback.
          const packageIds =
            Array.isArray(data.package_ids) && data.package_ids.length > 0
              ? data.package_ids
              : data.package_id
                ? [data.package_id]
                : [];
          if (packageIds.length > 0) {
            try {
              const packageRes = await fetch(`${window.sbConfig.apiBase}/packages`);
              if (packageRes.ok) {
                const allPackages = await packageRes.json();
                const packageDetails = allPackages.find(
                  (pkg: any) => pkg.id === Number(packageIds[0]),
                );
                setPackageData(packageDetails);
              }
            } catch (err) {
              console.error("Failed to fetch package details:", err);
            }
          }

          // Load all extras so package-included extras can show real names.
          try {
            const extrasRes = await fetch(`${window.sbConfig.apiBase}/extras/all`);
            if (extrasRes.ok) {
              const extrasData = await extrasRes.json();
              setAllExtras(Array.isArray(extrasData) ? extrasData : []);
            }
          } catch (err) {
            console.error("Failed to fetch extras catalog:", err);
          }

          setLoading(false);
        })
        .catch((err) => {
          console.error("Failed to fetch booking:", err);
          setError("Failed to load booking details.");
          setLoading(false);
        });
    } else {
      setLoading(false);
    }
  }, [bookingId]);

  if (loading) {
    return (
      <div className="sb-step sb-step-6">
        <p>Loading booking details...</p>
      </div>
    );
  }

  if (error || !bookingData) {
    return (
      <div className="sb-step sb-step-6">
        <p className="sb-error">{error || "Booking details not available."}</p>
        <button
          className="sb-btn sb-btn--primary"
          onClick={() => window.location.reload()}
        >
          Retry
        </button>
      </div>
    );
  }

  const formatTimeTo12Hour = (timeStr: string): string => {
    const [hourStr, minuteStr] = timeStr.split(":");
    let hour = parseInt(hourStr, 10);
    const minutes = minuteStr;
    const period = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minutes} ${period}`;
  };

  const getExtraTitle = (extraId: number, extra_name?: string) =>
    extra_name || `Extra #${extraId}`;

  const getExtraTitleById = (extraId: number): string => {
    const fromBooking = bookingData._extras_details?.find(
      (extra: ExtraDetail) => Number(extra.extra_id) === Number(extraId),
    );
    if (fromBooking?.extra_name) return fromBooking.extra_name;
    if (fromBooking?.title) return fromBooking.title;

    const fromCatalog = allExtras.find(
      (extra: ExtraCatalogItem) => Number(extra.id) === Number(extraId),
    );
    if (fromCatalog?.title) return fromCatalog.title;

    return `Extra #${extraId}`;
  };

  const getPackageSpaceIds = (): number[] => {
    if (!packageData) {
      return [];
    }

    if (Array.isArray(packageData.space_ids) && packageData.space_ids.length > 0) {
      return packageData.space_ids
        .map((id: number) => Number(id))
        .filter((id: number) => id > 0);
    }

    if (packageData.space_id) {
      return [Number(packageData.space_id)];
    }

    return [];
  };

  const getSpaceSummaryLabel = (): string => {
    const selectedSpaceItems = (bookingData._selected_items ?? []).filter(
      (item) => item.type === "sb_space",
    );
    const selectedSpaceIds = new Set(
      selectedSpaceItems.map((item) => Number(item.id)),
    );
    const packageSpaceIds = getPackageSpaceIds();
    const includedSpaceTitlesFromMeta = (bookingData._package_inclusions ?? [])
      .filter((inc: PackageInclusion) => inc.type === "sb_space" && !!inc.title)
      .map((inc: PackageInclusion) => inc.title.trim())
      .filter((title: string) => title.length > 0);
    const labels = selectedSpaceItems.map((item) => {
      const isPackageIncluded = packageSpaceIds.includes(Number(item.id));
      return `${item.title}${isPackageIncluded ? " (Package)" : ""}`;
    });

    packageSpaceIds.forEach((spaceId, index) => {
      if (selectedSpaceIds.has(spaceId)) {
        return;
      }

      const title =
        index === 0
          ? packageData?.space_name || `Space #${spaceId}`
          : `Space #${spaceId}`;
      labels.push(`${title} (Package)`);
    });

    includedSpaceTitlesFromMeta.forEach((title) => {
      const exists = labels.some(
        (label) =>
          label.toLowerCase() === title.toLowerCase() ||
          label.toLowerCase() === `${title.toLowerCase()} (package)`,
      );
      if (!exists) {
        labels.push(`${title} (Package)`);
      }
    });

    if (labels.length > 0) {
      return labels.join(", ");
    }

    if (packageSpaceIds.length > 0) {
      return packageSpaceIds
        .map((spaceId, index) => {
          const title = index === 0
            ? packageData?.space_name || `Space #${spaceId}`
            : `Space #${spaceId}`;
          return `${title} (Package)`;
        })
        .join(", ");
    }

    return bookingData._space_title || `Space #${bookingData.space_id}`;
  };

  const getPackageIncludedExtras = (): Array<{
    extra_id?: number;
    title: string;
  }> => {
    const byTitle = new Set<string>();
    const included: Array<{ extra_id?: number; title: string }> = [];

    const metaInclusions = (bookingData._package_inclusions ?? []).filter(
      (inc: PackageInclusion) => inc.type === "sb_extra" && !!inc.title,
    );

    metaInclusions.forEach((inc: PackageInclusion) => {
      const title = inc.title.trim();
      if (!title || byTitle.has(title.toLowerCase())) return;
      byTitle.add(title.toLowerCase());
      included.push({ title });
    });

    if (packageData?.extra_ids && Array.isArray(packageData.extra_ids)) {
      packageData.extra_ids.forEach((extraId: number) => {
        const title = getExtraTitleById(extraId);
        const key = title.toLowerCase();
        if (byTitle.has(key)) return;
        byTitle.add(key);
        included.push({ extra_id: extraId, title });
      });
    }

    return included;
  };

  const getExtrasDisplayItems = (): Array<{
    key: string;
    title: string;
    quantity?: number;
    unit_price?: number;
    isPackage: boolean;
  }> => {
    const regularExtras =
      bookingData._extras_details?.map((e: ExtraDetail) => ({
        key: `regular-${e.extra_id}`,
        title: e.extra_name || e.title || getExtraTitle(e.extra_id),
        quantity: e.quantity,
        unit_price: e.unit_price,
        isPackage: false,
      })) ?? [];

    const existingTitles = new Set(
      regularExtras.map((e) => e.title.trim().toLowerCase()),
    );

    const packageExtras = getPackageIncludedExtras()
      .filter((e) => !existingTitles.has(e.title.trim().toLowerCase()))
      .map((e, index) => ({
        key: `package-${e.extra_id ?? index}-${e.title}`,
        title: e.title,
        isPackage: true,
      }));

    return [...regularExtras, ...packageExtras];
  };

  const getPackageQuestionRows = (): Array<{ label: string; value: string }> => {
    const raw = bookingData._meta_data?._sb_package_question_answers;
    if (!raw) return [];

    let decoded: unknown;
    try {
      decoded = JSON.parse(raw);
    } catch {
      return [];
    }
    if (!Array.isArray(decoded)) return [];

    return decoded
      .map((entry) => {
        if (!entry || typeof entry !== "object") return null;
        const item = entry as {
          field_label?: string;
          value?: string | number | string[];
          others_text?: string;
        };
        const label = String(item.field_label || "").trim();
        const value = item.value;
        if (!label) return null;
        if (value === undefined || value === null) return null;
        if (typeof value === "string" && value.trim() === "") return null;
        if (Array.isArray(value) && value.length === 0) return null;
        const valueText = Array.isArray(value) ? value.join(", ") : String(value);
        const others = String(item.others_text || "").trim();
        return {
          label,
          value: others ? `${valueText} | Others: ${others}` : valueText,
        };
      })
      .filter((row): row is { label: string; value: string } => !!row);
  };

  const packageQuestionRows = getPackageQuestionRows();

  return (
    <div className="sb-step sb-step-6">
      {/* Success banner */}
      <div className="sb-confirm-banner">
        <span className="sb-confirm-icon" aria-hidden="true">
          ✅
        </span>
        <h2 className="sb-confirm-title">
          {bookingStatus === "in_review"
            ? "Payment In Review!"
            : "Booking Created!"}
        </h2>
        <p className="sb-confirm-subtitle">
          {bookingStatus === "in_review"
            ? "Payment successful! A human will review this booking to ensure total accuracy. You can expect a confirmation update from a member of our staff within 24 hours."
            : "You're being redirected to secure payment. A confirmation email will be sent after payment."}
        </p>
      </div>

      {/* Invoice card */}
      <div className="sb-invoice">
        <div className="sb-invoice__header">
          <h3>
            Booking Receipt (
            {bookingStatus === "in_review"
              ? "Paid - In Review"
              : "Pending Payment"}
            )
          </h3>
          {bookingData && (
            <span className="sb-invoice__id">#{bookingData.id}</span>
          )}
        </div>

        <table className="sb-invoice__table">
          <tbody>
            {/* <tr>
              <th>
                Space
                {bookingData._space_titles &&
                bookingData._space_titles.length > 1
                  ? "s"
                  : ""}
              </th>
              <td>
                {bookingData._space_titles &&
                bookingData._space_titles.length > 0
                  ? bookingData._space_titles.join(", ")
                  : bookingData._space_title ||
                    `Space #${bookingData.space_id}`}
              </td>
            </tr> */}
            <tr>
              <th>Space</th>
              <td>{getSpaceSummaryLabel()}</td>
            </tr>
            {bookingData._selected_items &&
              bookingData._selected_items.length > 1 && (
                <tr>
                  <th>Selected Items</th>
                  <td>
                    <ul style={{ margin: 0, paddingLeft: "20px" }}>
                      {bookingData._selected_items?.map((item) => (
                        <li key={item.id}>
                          {item.type === "sb_package" ? "📦" : "🏠"}{" "}
                          {item.title}
                        </li>
              ))}
            </ul>
          </td>
        </tr>
      )}

            <tr>
              <th>Date</th>
              <td>{bookingData.booking_date}</td>
            </tr>
            <tr>
              <th>Time</th>
              <td>
                {formatTimeTo12Hour(bookingData.start_time)} –{" "}
                {formatTimeTo12Hour(bookingData.end_time)}
              </td>
            </tr>
            <tr>
              <th>Duration</th>
              <td>{Number(bookingData.duration_hours).toFixed(1)} hours</td>
            </tr>
            <tr>
              <th>Name</th>
              <td>{bookingData.customer_name}</td>
            </tr>
            <tr>
              <th>Email</th>
              <td>{bookingData.customer_email}</td>
            </tr>
            {bookingData.customer_phone && (
              <tr>
                <th>Phone</th>
                <td>{bookingData.customer_phone}</td>
              </tr>
            )}
            {packageQuestionRows.length > 0 && (
              <tr>
                <th>Package Answers</th>
                <td>
                  <ul className="sb-confirm-extras">
                    {packageQuestionRows.map((row, index) => (
                      <li key={`${row.label}-${index}`}>
                        <strong>{row.label}:</strong> {row.value}
                      </li>
                    ))}
                  </ul>
                </td>
              </tr>
            )}
            <tr>
              <th>Extras</th>
              <td>
                {getExtrasDisplayItems().length > 0 ? (
                  <ul className="sb-confirm-extras">
                    {getExtrasDisplayItems().map((e) => (
                      <li key={e.key}>
                        {e.title}
                        {e.isPackage ? " (Package)" : ""}
                        {!e.isPackage && (e.quantity ?? 0) > 1 && ` × ${e.quantity}`}
                        {!e.isPackage && (e.unit_price ?? 0) > 0 && (
                          <span style={{ color: "#666", fontSize: "0.9em" }}>
                            {" "}
                            ({window.sbConfig.symbol}
                            {(e.unit_price ?? 0).toFixed(2)})
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <span style={{ color: "#999", fontStyle: "italic" }}>
                    None
                  </span>
                )}
              </td>
            </tr>
            <tr className="sb-invoice__total">
              <th>
                {bookingStatus === "in_review" ? "Total Paid" : "Total Due"}
              </th>
              <td>
                {parseFloat(bookingData.total_price.toString()).toFixed(2)}{" "}
                {window.sbConfig.symbol}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      {/* Actions */}
      <div className="sb-confirm-actions">
        <button className="sb-btn sb-btn--primary" onClick={reset}>
          Make Another Booking
        </button>
      </div>

      {bookingStatus === "in_review" && (
        <div
          className="sb-review-notice"
          style={{
            background: "#d4edda",
            padding: "15px",
            borderRadius: "8px",
            margin: "20px 0",
            borderLeft: "4px solid #28a745",
          }}
        >
          <strong>📋 Next Steps:</strong> Our team will review your booking
          within 24 hours and send final confirmation.
        </div>
      )}
      <p className="sb-confirm-lookup">
        {bookingStatus === "in_review"
          ? "Booking in review. Manage via "
          : "You'll receive confirmation after payment. Manage bookings via "}
        <a href="/booking-lookup/">booking lookup</a> with your email.
      </p>
    </div>
  );
}
