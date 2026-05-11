import type { Extra, MergedExtra } from "@/types";

const SYMBOL = window.sbConfig?.symbol || "$";

/**
 * Get the minimum quantity for an extra (based on included_qty from package)
 */
export function getMinQuantity(extraId: number, packageCoverage: { packageId: number; coveredSpaceIds: number[] }[], selectedItems: any[]): number {
  for (const pkg of packageCoverage) {
    const pkgItem = selectedItems.find(
      (i) => i.type === "package" && Number(i.id) === pkg.packageId
    );
    if (pkgItem && "extra_ids" in pkgItem && Array.isArray(pkgItem.extra_ids)) {
      if (pkgItem.extra_ids.includes(extraId)) {
        return 1; // Package includes at least 1
      }
    }
  }
  return 0;
}

/**
 * Check if quantity controls should be disabled for an extra
 */
export function isQuantityLocked(merged: MergedExtra | undefined): boolean {
  return merged?.is_locked ?? false;
}

/**
 * Check if minus button should be disabled
 * (when at minimum quantity - included_qty)
 */
export function isMinusDisabled(merged: MergedExtra | undefined): boolean {
  if (!merged) return true;
  return merged.total_qty <= merged.included_qty;
}

/**
 * Check if plus button should be disabled
 * (when extra is unavailable or at max inventory)
 */
export function isPlusDisabled(extra: Extra): boolean {
  return !extra.is_available;
}

/**
 * Render price display for an extra card
 * Shows split price for package-included extras
 */
export function renderExtraPriceDisplay(extra: Extra, merged: MergedExtra | undefined, packageTitle: string | null): JSX.Element {
  if (!merged) {
    // Not selected - show regular price
    return (
      <span className="sb-extra-card__price">
        {SYMBOL}{extra.price.toFixed(2)}
      </span>
    );
  }

  const { total_qty, included_qty, paid_qty, unit_price, is_locked } = merged;

  // Case 1: Fully included (locked)
  if (is_locked && paid_qty === 0) {
    return (
      <div className="sb-extra-card__price-split">
        <span className="sb-badge sb-badge--included">
          ✓ Included in {packageTitle || "Package"}
        </span>
        <span className="sb-extra-card__price sb-extra-card__price--free">
          {SYMBOL}0.00
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
        <span className="sb-extra-card__price sb-extra-card__price--additional">
          +{paid_qty} Additional: {SYMBOL}{(paid_qty * unit_price).toFixed(2)}
        </span>
      </div>
    );
  }

  // Case 3: No package - regular price
  return (
    <span className="sb-extra-card__price">
      {SYMBOL}{(total_qty * unit_price).toFixed(2)}
    </span>
  );
}

/**
 * Get quantity for an extra from selectedExtras
 */
export function getExtraQuantity(extraId: number, selectedExtras: { extra_id: number; quantity: number }[]): number {
  const sel = selectedExtras.find((e) => e.extra_id === extraId);
  return sel?.quantity ?? 0;
}