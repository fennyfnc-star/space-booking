import { useEffect, useState, useCallback } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { fetchSpaces, fetchPackages, fetchAllExtras } from "@/utils/api";
import type { Space, Package, Extra } from "@/types";

export function Step1Selection() {
  const formatTimeTo12Hour = (timeStr: string): string => {
    const [hourStr, minuteStr] = timeStr.split(":");
    let hour = parseInt(hourStr, 10);
    const minutes = minuteStr;
    const period = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minutes} ${period}`;
  };

  const {
    selectedItems,
    lockedResourceIds,
    packageCoverage,
    toggleItem,
    loadResourceMap,
    nextStep,
    hasCartBooking,
    checkCartBooking,
  } = useBookingStore();

  const [spaces, setSpaces] = useState<Space[]>([]);
  const [packages, setPackages] = useState<Package[]>([]);
  const [extras, setExtras] = useState<Extra[]>([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<"space" | "package">("space");

  // Load data and resource map
  useEffect(() => {
    setLoading(true);
    Promise.all([fetchSpaces(), fetchPackages(), fetchAllExtras(), loadResourceMap()])
      .then(([s, p, e]) => {
        setSpaces(s);
        setPackages(p);
        setExtras(e);
      })
      .finally(() => setLoading(false));
  }, [loadResourceMap]);

  // Helper: Resolve extra ID to title
  const getExtraTitle = (extraId: number): string => {
    const extra = extras.find(e => e.id === extraId);
    return extra?.title || `Extra #${extraId}`;
  };

  useEffect(() => {
    checkCartBooking();
  }, [checkCartBooking]);

  // Use store toggleItem directly
  const handleSelect = useCallback(
    (item: Space | Package) => {
      toggleItem(item);
    },
    [toggleItem],
  );

  // Check if item is locked (overlaps with locked resources) - task pattern
  const isLockedCard = useCallback(
    (item: Space | Package) => {
      const isSel = selectedItems.some((i) => Number(i.id) === Number(item.id));
      return lockedResourceIds.includes(Number(item.id)) && !isSel;
    },
    [lockedResourceIds, selectedItems],
  );

  // Check if item is selected - Number coercion
  const isSelectedCard = useCallback(
    (item: Space | Package) => {
      return selectedItems.some((i) => Number(i.id) === Number(item.id));
    },
    [selectedItems],
  );

  // Check if a space is covered by a selected package
  const isCoveredByPackage = useCallback(
    (item: Space | Package) => {
      const itemId = Number(item.id);
      const result = packageCoverage.some((pc) =>
        pc.coveredSpaceIds.includes(itemId),
      );
      console.log("🔍 isCoveredByPackage:", itemId, "result:", result, "packageCoverage:", packageCoverage);
      return result;
    },
    [packageCoverage],
  );

  // Get the package that covers a given space
  const getCoveringPackage = useCallback(
    (spaceId: number) => {
      const result = packageCoverage.find((pc) =>
        pc.coveredSpaceIds.includes(spaceId),
      );
      console.log("🔍 getCoveringPackage:", spaceId, "result:", result);
      return result;
    },
    [packageCoverage],
  );

  // Check if a package's spaces are already selected (should show lock)
  const isPackageBlockedBySelection = useCallback(
    (item: Space | Package) => {
      const pkg = item as Package;
      if (!pkg.space_ids || !Array.isArray(pkg.space_ids) || pkg.space_ids.length === 0) {
        return false;
      }
      // Check if any of the package's spaces are already selected
      const hasSelectedSpace = selectedItems.some((sel) =>
        pkg.space_ids!.includes(Number(sel.id)),
      );
      console.log("🔒 isPackageBlockedBySelection:", item.id, "space_ids:", pkg.space_ids, "hasSelectedSpace:", hasSelectedSpace);
      return hasSelectedSpace;
    },
    [selectedItems],
  );

  // Get the selected space names that block a package
  const getBlockingSpaceNames = useCallback(
    (item: Space | Package) => {
      const pkg = item as Package;
      if (!pkg.space_ids || !Array.isArray(pkg.space_ids)) return [];
      return selectedItems
        .filter((sel) => pkg.space_ids!.includes(Number(sel.id)))
        .map((sel) => sel.title);
    },
    [selectedItems],
  );

  const canProceed = selectedItems.length > 0 && !hasCartBooking;

  const renderCard = (item: Space | Package, type: "space" | "package") => {
    const selected = isSelectedCard(item);
    const locked = isLockedCard(item);
    const covered = isCoveredByPackage(item);
    const packageBlocked = type === "package" && isPackageBlockedBySelection(item);
    const blockingNames = type === "package" ? getBlockingSpaceNames(item) : [];
    const space = item as Space;
    const overrides = space.price_overrides ?? [];
    const packageSpaceIds = (item as Package).space_ids ?? [];
    const packageExtraIds = (item as Package).extra_ids ?? [];
    const itemId = item.id;
    
    // Card is locked if either locked by physical overlap OR blocked by selected spaces
    const isCardLocked = locked || packageBlocked;
    
    console.log("🎴 renderCard:", itemId, "type:", type, "selected:", selected, "locked:", locked, "covered:", covered, "packageBlocked:", packageBlocked);

    return (
      <div
        key={itemId}
        className={`sb-card 
          ${selected ? "sb-card--selected" : ""} 
          ${isCardLocked ? "sb-card--locked opacity-50 cursor-not-allowed" : "cursor-pointer"}
        `}
        onClick={!isCardLocked ? () => handleSelect(item) : undefined}
        role="button"
        tabIndex={isCardLocked ? -1 : 0}
        onKeyDown={(e) => {
          if (e.key === "Enter" && !isCardLocked) handleSelect(item);
        }}
      >
        <img
          src={
            item.thumbnail ??
            "https://upload.wikimedia.org/wikipedia/commons/1/14/No_Image_Available.jpg"
          }
          alt={item.title}
          className="sb-card__img"
        />
        <div className="sb-card__body">
          <h3 className="sb-card__title">{item.title}</h3>
          {type === "space" ? (
            <>
              <p className="sb-card__excerpt">{(item as Space).excerpt}</p>
              <div className="sb-card__price">
                <div>
                  Regular: {window.sbConfig.symbol}
                  {(item as Space).hourly_rate.toFixed(2)} / hr
                </div>
                <div>Min: {(item as Space).min_duration}h booking</div>
                {(item as Space).capacity > 0 && (
                  <div>Up to {(item as Space).capacity} guests</div>
                )}
                {overrides.length > 0 && (
                  <div className="sb-price-overrides">
                    {overrides.map((ov: any, idx: number) => {
                      const todayDay = new Date().getDay();
                      const appliesToday = ov.days.includes(todayDay);
                      const dayNames = [
                        "Sun",
                        "Mon",
                        "Tue",
                        "Wed",
                        "Thu",
                        "Fri",
                        "Sat",
                      ];
                      const dayLabel = ov.days
                        .map((d: number) => dayNames[d])
                        .join(", ");
                      return (
                        <div key={idx} className="sb-override">
                          <span>
                            {dayLabel} {formatTimeTo12Hour(ov.start_time)}-
                            {formatTimeTo12Hour(ov.end_time)}:
                          </span>
                          <span>
                            {window.sbConfig.symbol}
                            {ov.hourly_rate.toFixed(2)}/hr
                          </span>
                          {appliesToday && (
                            <span className="sb-active-override">✓</span>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </>
          ) : (
            <>
              <p className="sb-card__excerpt">
                {(item as Package).description}
              </p>
              <p className="sb-card__price">
                {window.sbConfig.symbol}
                {(item as Package).price.toFixed(2)} flat
                {(item as Package).space_name &&
                  ` · ${(item as Package).space_name}`}
                {(item as Package).duration > 0 &&
                  ` · ${(item as Package).duration}h`}
              </p>
              
              {/* Display package inclusions */}
              <div className="sb-package-inclusions">
                {packageSpaceIds.length > 0 && (
                  <div className="sb-package-spaces">
                    <span className="font-medium">Includes spaces:</span> {spaces
                      .filter(space => packageSpaceIds.includes(space.id))
                      .map(space => space.title)
                      .join(', ')}
                  </div>
                )}
                
                {packageExtraIds.length > 0 && (
                  <div className="sb-package-extras">
                    <span className="font-medium">Includes extras:</span> {packageExtraIds
                      .map(extraId => getExtraTitle(extraId))
                      .join(', ')}
                  </div>
                )}
              </div>
            </>
          )}
        </div>
        {selected && (
          <span className="sb-card__check" aria-label="Selected">
            ✓
          </span>
        )}
        {isCardLocked && (
          <span
            className="sb-card__lock"
            aria-label={packageBlocked ? "Locked by selected space" : "Locked by package selection"}
          >
            🔒
          </span>
        )}
        {/* Show "Included in package" badge for spaces covered by selected packages */}
        {type === "space" && covered && !selected && (
          <span
            className="sb-card__badge sb-card__badge--package"
            aria-label="Included in package"
          >
            📦 Included in "{getCoveringPackage(Number(item.id))?.packageTitle}"
          </span>
        )}
        {/* Show "Space selected" badge for packages blocked by selected spaces */}
        {type === "package" && packageBlocked && !selected && blockingNames.length > 0 && (
          <span
            className="sb-card__badge sb-card__badge--space"
            aria-label="Blocked by selected space"
          >
            🔒 Space already selected: {blockingNames.join(", ")}
          </span>
        )}
      </div>
    );
  };

  return (
    <div className="sb-step sb-step-1">
      <h2 className="sb-step__title">
        Choose Spaces or Packages (Multi-Select)
      </h2>
      <p className="sb-step__subtitle">
        {/* Selected: {selectedItems.length} items | Locked resources:{" "}
        {lockedResourceIds.length} */}
      </p>

      {/* Tabs */}
      <div className="sb-tabs">
        <button
          className={`sb-tab ${tab === "space" ? "sb-tab--active" : ""}`}
          onClick={() => setTab("space")}
        >
          Spaces ({spaces.length})
        </button>
        <button
          className={`sb-tab ${tab === "package" ? "sb-tab--active" : ""}`}
          onClick={() => setTab("package")}
        >
          Packages ({packages.length})
        </button>
      </div>

      {loading && (
        <div className="sb-loading">Loading options and resource map…</div>
      )}

      {/* Spaces */}
      {!loading && tab === "space" && (
        <div className="sb-cards">
          {spaces.map((space) => renderCard(space, "space"))}
          {spaces.length === 0 && (
            <p className="sb-empty">No spaces available.</p>
          )}
        </div>
      )}

      {/* Packages */}
      {!loading && tab === "package" && (
        <div className="sb-cards">
          {packages.map((pkg) => renderCard(pkg, "package"))}
          {packages.length === 0 && (
            <p className="sb-empty">No packages available.</p>
          )}
        </div>
      )}

      {/* Cart Booking Modal - unchanged */}
      {hasCartBooking && (
        <div
          className="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm flex items-center justify-center z-50"
          onClick={(e) => e.stopPropagation()}
        >
          <div className="bg-white p-8 rounded-lg max-w-md mx-4 text-center">
            <h3 className="text-xl font-bold mb-4 text-gray-900">
              Ongoing Booking Detected
            </h3>
            <p className="mb-6 text-gray-600">
              You already have an ongoing booking in your cart.
            </p>
            <div className="space-y-3">
              <button
                className="w-full sb-btn sb-btn--primary py-3"
                onClick={(e) => {
                  e.stopPropagation();
                  window.location.href = "/checkout/";
                }}
              >
                Proceed to Checkout
              </button>
              <button
                className="w-full sb-btn sb-btn--secondary py-3"
                onClick={async (e) => {
                  e.stopPropagation();
                  console.log("Clear cart & start new booking");
                }}
              >
                Delete Previous & Start Again
              </button>
            </div>
          </div>
        </div>
      )}

      <div className="sb-step__actions">
        <button
          className="sb-btn sb-btn--primary"
          disabled={!canProceed}
          onClick={nextStep}
        >
          Continue → ({selectedItems.length} selected)
        </button>
      </div>
    </div>
  );
}
