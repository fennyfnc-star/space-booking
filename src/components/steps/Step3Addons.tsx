import { useEffect, useState } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { fetchExtras, fetchPricing } from "@/utils/api";
import type { Extra, Package, PricingResponse } from "@/types";

interface PreviewItem {
  label: string;
  amount: number;
}

export function Step3Addons() {
  const {
    selectedItems,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    selectedExtras,
    toggleExtra,
    setAvailableExtras,
    setPriceBreakdown,
    nextStep,
    prevStep,
  } = useBookingStore();

  const [extras, setExtras] = useState<Extra[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<{
    total: number;
    breakdown: PreviewItem[];
  } | null>(null);

  const resolveSelectedSpaceId = (): number => {
    for (const item of selectedItems) {
      if (item.type === "space") {
        return Number(item.id);
      }

      if (item.type === "package") {
        const pkg = item as Package;
        if (Array.isArray(pkg.space_ids) && pkg.space_ids.length > 0) {
          return pkg.space_ids[0];
        }
        if (pkg.space_id) {
          return Number(pkg.space_id);
        }
      }
    }

    return 0;
  };

  const spaceId = resolveSelectedSpaceId();
  const selectedPackages = selectedItems.filter(
    (item) => item.type === "package",
  ) as Package[];

  useEffect(() => {
    if (!spaceId || !selectedDate || !selectedStartTime || !selectedEndTime) {
      return;
    }

    setLoading(true);
    setError("");

    fetchExtras(spaceId, selectedDate, selectedStartTime, selectedEndTime)
      .then((data) => {
        setExtras(data);
        setAvailableExtras(data);
      })
      .catch((e: Error) => {
        setError(e.message);
      })
      .finally(() => setLoading(false));
  }, [
    spaceId,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    setAvailableExtras,
  ]);

  useEffect(() => {
    if (!spaceId || !selectedDate || !selectedStartTime || !selectedEndTime) {
      return;
    }

    const itemIds = selectedItems.map((item) => Number(item.id));
    const packageIds = selectedItems
      .filter((item) => item.type === "package")
      .map((item) => Number(item.id));

    fetchPricing({
      space_id: spaceId,
      item_ids: itemIds,
      date: selectedDate,
      start_time: selectedStartTime,
      end_time: selectedEndTime,
      extras: selectedExtras,
      package_ids: packageIds,
    })
      .then((res: PricingResponse) => {
        setPreview({
          total: res.total_price,
          breakdown: res.breakdown,
        });
        setPriceBreakdown(res.breakdown, res.total_price, res.extras_details);
      })
      .catch((e) => {
        console.error("Failed to fetch pricing:", e);
      });
  }, [
    selectedDate,
    selectedEndTime,
    selectedExtras,
    selectedItems,
    selectedStartTime,
    setPriceBreakdown,
    spaceId,
  ]);

  const isIncludedInPackage = (extraId: number): boolean => {
    return selectedPackages.some(
      (pkg) => Array.isArray(pkg.extra_ids) && pkg.extra_ids.includes(extraId),
    );
  };

  const isPackageOwned = (extra: Extra): boolean => {
    if (Array.isArray(extra.package_ids) && extra.package_ids.length > 0) {
      return true;
    }

    return selectedPackages.some(
      (pkg) => Array.isArray(pkg.extra_ids) && pkg.extra_ids.includes(extra.id),
    );
  };

  const isSelected = (extraId: number) =>
    selectedExtras.some((extra) => extra.extra_id === extraId);

  return (
    <div className="sb-step sb-step-3">
      <h2 className="sb-step__title">Add-ons & Extras</h2>

      {loading && <div className="sb-loading">Loading extras…</div>}
      {error && <div className="sb-error">{error}</div>}

      {!loading && extras.length === 0 && (
        <p className="sb-empty">No extras available for this time slot.</p>
      )}

      {!loading && extras.length > 0 && (
        <div className="sb-extras">
          {extras.map((extra) => {
            if (!isIncludedInPackage(extra.id) && isPackageOwned(extra)) {
              return null;
            }

            return (
              <div
                key={extra.id}
                className={`sb-extra-card ${isSelected(extra.id) ? "sb-extra-card--selected" : ""} ${!extra.is_available ? "sb-extra-card--unavailable" : ""}`}
              >
                <div className="sb-extra-card__info">
                  {extra.thumbnail && (
                    <img
                      src={extra.thumbnail}
                      alt={extra.title}
                      className="sb-extra-card__img"
                    />
                  )}
                  <div>
                    <strong className="sb-extra-card__name">
                      {extra.title}
                    </strong>
                    <p className="sb-extra-card__desc">{extra.description}</p>
                    <span className="sb-extra-card__price">
                      {window.sbConfig.symbol}
                      {extra.price.toFixed(2)}
                    </span>
                    {!extra.is_available && extra.unavailable_reason && (
                      <span
                        className={`sb-badge sb-badge--sold-out ${extra.unavailable_reason === "space_override" ? "sb-badge--closed" : ""}`}
                      >
                        {extra.unavailable_reason === "space_override"
                          ? "Closed this time"
                          : "Sold Out"}
                      </span>
                    )}
                    {extra.is_available && extra.available_qty < 3 && (
                      <span className="sb-badge sb-badge--low">
                        Only {extra.available_qty} left
                      </span>
                    )}
                  </div>
                </div>

                {isIncludedInPackage(extra.id) ? (
                  <span className="sb-badge sb-badge--included">
                    ✓ Included
                  </span>
                ) : isPackageOwned(extra) ? (
                  <span className="sb-badge sb-badge--package-only">
                    Package Only
                  </span>
                ) : (
                  <button
                    className={`sb-btn ${isSelected(extra.id) ? "sb-btn--danger" : "sb-btn--secondary"}`}
                    disabled={!extra.is_available}
                    onClick={() => toggleExtra(extra.id)}
                  >
                    {isSelected(extra.id) ? "Remove" : "Add"}
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}

      {preview && (
        <div className="sb-price-preview">
          <h4>Price Preview</h4>
          <ul className="sb-breakdown">
            {preview.breakdown.map((item, i) => (
              <li
                key={i}
                className={`sb-breakdown__item ${item.label.includes("(Package Inclusion)") ? "package-inclusion" : ""}`}
              >
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
              {preview.total.toFixed(2)}
            </strong>
          </div>
        </div>
      )}

      <div className="sb-step__actions">
        <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
          ← Back
        </button>
        <button className="sb-btn sb-btn--primary" onClick={nextStep}>
          Continue →
        </button>
      </div>
    </div>
  );
}
