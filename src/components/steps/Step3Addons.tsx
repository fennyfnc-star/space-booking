import React, { useEffect, useState, useMemo } from "react";
import { useBookingStore, type MergedExtra } from "@/store/bookingStore";
import { fetchExtras, fetchPricing } from "@/utils/api";
import type {
  Extra,
  PriceBreakdownItem,
  PricingResponse,
  SelectedExtra,
  Space,
  Package,
  SelectionItem,
} from "@/types";

// Icons as simple SVG components
const MinusIcon = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
    <path d="M3 8h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

const PlusIcon = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
    <path d="M8 3v10M3 8h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

interface EnrichedBreakdownItem {
  label: string;
  amount: number;
}

export function Step3Addons() {
  const {
    selectedItems,
    lockedResourceIds,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    selectedExtras,
    availableExtras,
    toggleExtra,
    incrementExtra,
    decrementExtra,
    setAvailableExtras,
    setPriceBreakdown,
    nextStep,
    prevStep,
    getMergedExtras,
    packageCoverage,
  } = useBookingStore();

  // Get first resolved space ID (not package ID) for API calls
  // When package is selected, lockedResourceIds[0] is the package ID, so we need to resolve the actual space
  const getResolvedSpaceId = (): number => {
    for (const item of selectedItems) {
      if (item.type === "space") {
        return Number(item.id);
      }
      if (item.type === "package") {
        const pkg = item as Package;
        if (pkg.space_ids && Array.isArray(pkg.space_ids) && pkg.space_ids.length > 0) {
          return pkg.space_ids[0];
        }
        if (pkg.space_id) {
          return Number(pkg.space_id);
        }
      }
    }
    return 0;
  };
  const spaceId = getResolvedSpaceId();

  const pkgItem = selectedItems.find(
    (item: SelectionItem) => item.type === "package",
  ) as Package | undefined;
  const packageId = pkgItem?.id;
  const primarySpace = selectedItems.find(
    (item: SelectionItem) => item.type === "space",
  ) as Space | undefined;

  // Entry logging AFTER state is available
  console.group("🚀 STEP3 ADDONS - Entry Props");
  console.log("spaceId:", spaceId);
  console.log("selectedDate:", selectedDate);
  console.log("selectedStartTime:", selectedStartTime);
  console.log("selectedEndTime:", selectedEndTime);
  console.log("pkgItem:", pkgItem);
  console.log("primarySpace:", primarySpace);
  console.log("selectedExtras:", selectedExtras);
  console.groupEnd();

  const [extras, setExtras] = useState<Extra[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<{
    total: number;
    breakdown: EnrichedBreakdownItem[];
  } | null>(null);

  useEffect(() => {
    if (!spaceId || !selectedDate || !selectedStartTime || !selectedEndTime) {
      console.log("STEP3: Missing required params, skipping fetchExtras");
      return;
    }

    // Auto-add package-included extras to selectedExtras (only if not already selected)
    if (pkgItem) {
      const pkg = pkgItem as Package;
      const includedExtraIds: number[] = [];
      if (pkg.extra_ids && Array.isArray(pkg.extra_ids)) {
        includedExtraIds.push(...pkg.extra_ids);
      } else if (pkg.extra_id) {
        includedExtraIds.push(pkg.extra_id);
      }
      // Add included extras only if not already in selectedExtras
      if (includedExtraIds.length > 0) {
        const alreadySelected = selectedExtras.map((e) => e.extra_id);
        const toAdd = includedExtraIds.filter((id) => !alreadySelected.includes(id));
        if (toAdd.length > 0) {
          console.log("📦 Auto-selecting package extras:", toAdd);
          for (const extraId of toAdd) {
            toggleExtra(extraId, 1, true); // quantity=1, included=true
          }
        }
      }
    }

    console.group("📦 STEP3 fetchExtras");
    console.log("Params:", {
      spaceId,
      selectedDate,
      selectedStartTime,
      selectedEndTime,
    });

    setLoading(true);
    fetchExtras(spaceId, selectedDate, selectedStartTime, selectedEndTime)
      .then((data) => {
        console.log("✅ fetchExtras RAW RESPONSE:", data);
        console.log("Extras count:", data.length);
        if (data.length === 0) {
          console.warn("⚠️ Backend returned EMPTY extras array!");
        }
        setExtras(data);
        setAvailableExtras(data);
        console.groupEnd();
      })
      .catch((e: Error) => {
        console.error("❌ fetchExtras ERROR:", e.message);
        setError(e.message);
        console.groupEnd();
      })
      .finally(() => setLoading(false));
  }, [
    spaceId,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    setAvailableExtras,
  ]);

  // Re-calculate price whenever extras selection changes
  useEffect(() => {
    if (!spaceId || !selectedDate || !selectedStartTime || !selectedEndTime) {
      console.log("STEP3: Missing params, skipping fetchPricing");
      return;
    }

    console.group("💰 STEP3 fetchPricing");
    
    // Build item_ids - include package ID when package is selected so backend uses flat price
    const itemIds: number[] = [];
    if (packageId) {
      // Package selected: use package ID so backend applies flat price
      itemIds.push(Number(packageId));
    } else {
      // Space-only: use space IDs
      for (const item of selectedItems) {
        if (item.type === "space") {
          itemIds.push(Number(item.id));
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
      package_id: packageId,
    };
    console.log("Params sent to /pricing:", pricingParams);

    fetchPricing(pricingParams)
      .then((res: PricingResponse) => {
        console.log("✅ fetchPricing FULL RESPONSE:", res);
        console.log("Total:", res.total_price);
        console.log("Breakdown:", res.breakdown);
        // Backend now provides detailed labels, no frontend enrichment needed
        setPreview({ total: res.total_price, breakdown: res.breakdown });
        setPriceBreakdown(res.breakdown, res.total_price);

        console.groupEnd();
      })
      .catch((error) => {
        console.error("❌ fetchPricing ERROR:", error);
        console.groupEnd();
      });
  }, [
    selectedExtras,
    availableExtras,
    primarySpace,
    pkgItem,
    spaceId,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    packageId,
  ]);

  // Get merged extras for UI display
  const mergedExtras = getMergedExtras();
  
  // Helper to get package title for badge
  const getPackageTitle = () => {
    if (packageCoverage.length === 0) return null;
    return packageCoverage[0].packageTitle;
  };
  const packageTitle = getPackageTitle();

  // Check if extra is included in package
  const isIncludedInPackage = (extraId: number): boolean => {
    if (!pkgItem) return false;
    const pkg = pkgItem as Package;
    const extraIds = pkg.extra_ids || [];
    return extraIds.includes(extraId);
  };

  const isSelected = (extraId: number) =>
    selectedExtras.some((e) => e.extra_id === extraId);

  // Get merged extra for an ID
  const getMergedExtra = (id: number): MergedExtra | undefined =>
    mergedExtras.find((m) => m.extra_id === id);

  // Render price with included/paid split
  const renderExtraPrice = (extra: Extra, merged: MergedExtra | undefined) => {
    if (!merged) {
      // Not selected or no merged data - show regular price
      return (
        <span className="sb-extra-card__price">
          {window.sbConfig.symbol}{extra.price.toFixed(2)}
        </span>
      );
    }

    const { total_qty, included_qty, paid_qty, unit_price, is_locked } = merged;

    // Case 1: Fully included (locked)
    if (is_locked && paid_qty === 0) {
      return (
        <div className="sb-extra-card__price-split">
          <span className="sb-badge sb-badge--included">
            ✓ Included in {packageTitle}
          </span>
          <span className="sb-extra-card__price">
            {window.sbConfig.symbol}0.00
          </span>
        </div>
      );
    }

    // Case 2: Partially included (some paid, some free)
    if (included_qty > 0 && paid_qty > 0) {
      return (
        <div className="sb-extra-card__price-split">
          <span className="sb-badge sb-badge--included">
            ✓ {included_qty} Included
          </span>
          <span className="sb-extra-card__price">
            +{paid_qty} Additional: {window.sbConfig.symbol}{(paid_qty * unit_price).toFixed(2)}
          </span>
        </div>
      );
    }

    // Case 3: No package - regular price
    return (
      <span className="sb-extra-card__price">
        {window.sbConfig.symbol}{extra.price.toFixed(2)}
      </span>
    );
  };

  // Check if quantity controls should be disabled
  const isQuantityLocked = (extraId: number): boolean => {
    const merged = getMergedExtra(extraId);
    return merged?.is_locked ?? false;
  };

  // Helper to render price display for an extra
  const renderPriceDisplay = (extra: Extra) => {
    const merged = getMergedExtra(extra.id);
    if (!merged) {
      // Not selected - show regular price
      return (
        <span className="sb-extra-card__price">
          {window.sbConfig.symbol}
          {extra.price.toFixed(2)}
        </span>
      );
    }

    // Selected - show split price display
    const { total_qty, included_qty, paid_qty, unit_price, is_locked } = merged;

    return (
      <div className="sb-extra-card__price-split">
        {included_qty > 0 && (
          <>
            <span className="sb-extra-card__price--included">
              ✓ Included in {packageTitle || "Package"}
            </span>
            {paid_qty > 0 && (
              <span className="sb-extra-card__price--additional">
                +{paid_qty} Additional: {window.sbConfig.symbol}
                {(paid_qty * unit_price).toFixed(2)}
              </span>
            )}
            {paid_qty === 0 && (
              <span className="sb-extra-card__price--free">
                {window.sbConfig.symbol}0.00
              </span>
            )}
          </>
        )}
        {included_qty === 0 && (
          <span className="sb-extra-card__price">
            {window.sbConfig.symbol}
            {(total_qty * unit_price).toFixed(2)}
          </span>
        )}
      </div>
    );
  };

  // Helper to get quantity for an extra
  const getQuantity = (id: number): number => {
    const sel = selectedExtras.find((e) => e.extra_id === id);
    return sel?.quantity ?? 0;
  };

  // Helper to check if minus should be disabled
  const isMinusDisabled = (id: number): boolean => {
    const merged = getMergedExtra(id);
    if (!merged) return true;
    // Disable minus when at minimum (included_qty)
    return merged.total_qty <= merged.included_qty;
  };

  // Helper to check if plus should be disabled (at max inventory)
  const isPlusDisabled = (id: number): boolean => {
    const extra = extras.find((e) => e.id === id);
    if (!extra || !extra.is_available) return true;
    const merged = getMergedExtra(id);
    const maxQty = extra.available_qty ?? 999;
    // Disable if at max available quantity
    return merged ? merged.total_qty >= maxQty : false;
  };

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
          {extras.map((extra) => (
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
                  <strong className="sb-extra-card__name">{extra.title}</strong>
                  <p className="sb-extra-card__desc">{extra.description}</p>
                  <span className="sb-extra-card__price">
                    {window.sbConfig.symbol}
                    {extra.price.toFixed(2)}
                  </span>
                  {!extra.is_available && extra.unavailable_reason && (
                    <span
                      className={`sb-badge sb-badge--sold-out ${
                        extra.unavailable_reason === "space_override"
                          ? "sb-badge--closed"
                          : ""
                      }`}
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
              {/* Show "Included" badge if extra is in package, otherwise show Add/Remove button */}
              {isIncludedInPackage(extra.id) ? (
                <span className="sb-badge sb-badge--included">✓ Included</span>
              ) : (
                <button
                  className={`sb-btn ${isSelected(extra.id) ? "sb-btn--danger" : "sb-btn--secondary"}`}
                  disabled={!extra.is_available}
                  onClick={() => {
                    console.group("🔄 STEP3 Toggle Extra");
                    console.log("Toggling extra ID:", extra.id);
                    console.log("Current selectedExtras:", selectedExtras);
                    toggleExtra(extra.id);
                    console.log("After toggle - should trigger pricing refetch");
                    console.groupEnd();
                  }}
                >
                  {isSelected(extra.id) ? "Remove" : "Add"}
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Live price preview */}
      {preview && (
        <div className="sb-price-preview">
          <h4>Price Preview</h4>
          <ul className="sb-breakdown">
            {preview.breakdown.map((item, i) => (
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
