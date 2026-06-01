import { useEffect, useState } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { fetchMultiAvailability } from "@/utils/api";
import type { AvailabilityResponse, TimeSlot, Package } from "@/types";

import { fetchPricing } from "@/utils/api";

export function Step2Scheduling() {
  const {
    selectedItems,
    selectedDate,
    selectedStartTime,
    selectedEndTime,
    setDate,
    setStartTime,
    setEndTime,
    nextStep,
    prevStep,
    getLockedResourceIds, // Use getter to compute fresh each time
  } = useBookingStore();

  // NOTE: We don't call getLockedResourceIds() at component scope
  // because it would get stale on initial render. We always call it
  // INSIDE useEffect or handlers to get FRESH values.

  const [slots, setSlots] = useState<TimeSlot[]>([]);
  const [apiResponse, setApiResponse] = useState<AvailabilityResponse | null>(
    null,
  );
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [, setPricePreview] = useState(0);
  const [, setPriceLoading] = useState(false);
  const [blockers, setBlockers] = useState<
    { id: number; title: string; reason?: string }[]
  >([]);

  const timeToMinutes = (timeStr: string): number => {
    const [h, m] = timeStr.split(":").map(Number);
    return h * 60 + m;
  };

  const formatTimeTo12Hour = (timeStr: string): string => {
    const [hourStr, minuteStr] = timeStr.split(":");
    let hour = parseInt(hourStr, 10);
    const minutes = minuteStr;
    const period = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minutes} ${period}`;
  };

  // NEW: Compute min/max duration across ALL selected items
  // minDuration = maximum of all min_durations (most restrictive)
  // maxDuration = minimum of all max_durations (most restrictive)
  const computedDurations = (() => {
    if (selectedItems.length === 0) {
      return { minDuration: 1, maxDuration: 8 };
    }
    let minDur = Infinity;
    let maxDur = -Infinity;
    for (const item of selectedItems) {
      const itemMin = (item as any)?.min_duration ?? 1;
      const itemMax = (item as any)?.max_duration ?? 8;
      if (itemMin < minDur) minDur = itemMin;
      if (itemMax > maxDur) maxDur = itemMax;
    }
    return {
      minDuration: minDur === Infinity ? 1 : minDur,
      maxDuration: maxDur === -Infinity ? 8 : maxDur,
    };
  })();
  const minDuration = computedDurations.minDuration;
  const maxDuration = computedDurations.maxDuration;

  const hasFixedSlots =
    apiResponse?.has_fixed_slots ?? slots.some((s) => s.slot_id);
  const apiMessage = apiResponse?.message;

  const isStartValid = (slotIndex: number): boolean => {
    if (slotIndex + minDuration > slots.length) return false;
    for (let k = 0; k < minDuration; k++) {
      if (!slots[slotIndex + k].available) return false;
    }
    return true;
  };

  // Fixed slot selection handler - uses first item from selectedItems for pricing
  const selectFixedSlot = async (slot: TimeSlot) => {
    if (!slot.available) return;

    setStartTime(slot.start);
    setEndTime(slot.end);

    if (slot.override_price) {
      setPricePreview(slot.override_price);
    } else {
      // ARRAY-ONLY: Always use fresh resource IDs
      setPriceLoading(true);
      try {
        // Use the first explicit space for the deprecated leading-space field.
        const firstSpaceId = selectedItems.find((i) => i.type === "space")
          ? Number(selectedItems.find((i) => i.type === "space")!.id)
          : 0;
        const packageIds = useBookingStore.getState().getAllPackageIds();
        const itemIds = selectedItems.map((item) => Number(item.id));
        
        const pricing = await fetchPricing({
          space_id: firstSpaceId,
          date: selectedDate!,
          start_time: slot.start,
          item_ids: itemIds,
          end_time: slot.end,
          extras: [],
          package_ids: packageIds,
        });
        setPricePreview(pricing.total_price);
      } catch (e) {
        console.error("Price preview failed:", e);
      } finally {
        setPriceLoading(false);
      }
    }
  };

  // Auto-set first valid end time (>= minDuration) when start changes (SKIP for fixed slots)
  useEffect(() => {
    if (hasFixedSlots) return; // Fixed slots handle their own end_time
    const firstValidEnd = getFirstValidEnd();
    if (firstValidEnd) {
      setEndTime(firstValidEnd);
    }
  }, [selectedStartTime, slots, minDuration, hasFixedSlots]);

  // Minimum selectable date = today
  const today = new Date().toISOString().split("T")[0];

  // ARRAY-ONLY: Load resourceMap ONCE when component mounts
  // CRITICAL: Wait for resourceMap to BE FULLY LOADED before using getLockedResourceIds
  const resourceMap = useBookingStore((s) => s.resourceMap);
  const [isMapReady, setIsMapReady] = useState(false);

  useEffect(() => {
    if (!resourceMap) {
      useBookingStore
        .getState()
        .loadResourceMap()
        .then(() => {
          setIsMapReady(true);
        });
    } else {
      setIsMapReady(true);
    }
  }, []);

  // GUARDRAIL: Wait for resourceMap to FULLY LOAD before fetching availability
  // ARRAY-ONLY MANDATE: Must use the ENTIRE array, not single ID
  // CRITICAL: Re-fetch when EITHER isMapReady OR selectedItems changes
  useEffect(() => {
    if (!isMapReady) {
      return;
    }

    // ARRAY-ONLY MANDATE: Always compute fresh IDs from selectedItems
    // Uses Append/Remove pattern with cumulative group selection
    // Resolve selected items to space IDs (handle packages)
    const freshSpaceIds = getLockedResourceIds();

    // FIX BUG 2: Separate spaces from packages
    // - Only add EXPLICITLY selected spaces to spaceIds
    // - Packages go to packageIds (backend resolves them)
    // This prevents false positive where package's included space conflicts with itself

    // NEW: Extract package IDs from selectedItems for conflict detection
    const packageIds = selectedItems
      .filter((item) => item.type === "package")
      .map((item) => Number(item.id));

    // Add ALL selected spaces (including package's included spaces)
    // Frontend resolves package's space_ids so backend gets complete list
    let spaceIds: number[] = [];
    for (const item of selectedItems) {
      if (item.type === "space") {
        spaceIds.push(Number(item.id));
      } else if (item.type === "package") {
        // Add package's included spaces
        const pkg = item as Package;
        if (pkg.space_ids && Array.isArray(pkg.space_ids)) {
          spaceIds.push(...pkg.space_ids);
        }
      }
    }
    // Also add any locked resource IDs that aren't already selected (physical resources)
    // BUT: Only add if they're SPACE IDs, not package IDs
    for (const id of freshSpaceIds) {
      const isSelected = selectedItems.some(i => Number(i.id) === id);
      const isPackage = selectedItems.some(i => i.type === "package" && Number(i.id) === id);
      if (!isSelected && !isPackage) {
        // Only add if it's a space (check via resourceMap type)
        const itemType = resourceMap?.[id]?.type;
        if (itemType === "space") {
          spaceIds.push(id);
        }
        // Skip packages - they're handled separately via packageIds
      }
    }
    // Dedupe
    spaceIds = [...new Set(spaceIds)];

    // FIX: Allow API call when packages are selected (even without explicit spaces)
    // Package-only bookings should resolve to their included space
    if (!selectedDate || (spaceIds.length === 0 && packageIds.length === 0)) {
      // No spaces or packages selected yet
      setSlots([]);
      setApiResponse(null);
      return;
    }

    setLoading(true);
    setError("");
    setApiResponse(null);
    setBlockers([]);

    // Always use multi-space endpoint - works for single or multiple spaces
    // UPDATED: Now passes packageIds for package-space conflict detection
    fetchMultiAvailability(spaceIds, selectedDate, packageIds)
      .then((res) => {
        // Extract blockers
        if (res.blockers && res.blockers.length > 0) {
          setBlockers(res.blockers);
        }

        // Sort slots chronologically by start time before storing
        const sortedSlots = [...res.slots].sort((a, b) => {
          const timeToMins = (t: string) => {
            const [h, m] = t.split(":").map(Number);
            return h * 60 + m;
          };
          return timeToMins(a.start) - timeToMins(b.start);
        });
        setSlots(sortedSlots);
        setApiResponse(res);
      })
      .catch((e: Error) => {
        console.error("AVAIL ERROR:", e.message);
        setError(e.message);
      })
      .finally(() => setLoading(false));
    // GUARDRAIL: Re-fetch when either resourceMap becomes ready OR selection changes
  }, [selectedDate, selectedItems, isMapReady]);

  // Sequential available end slots starting from minDuration (excluding default)
  const endTimeOptions: TimeSlot[] = [];
  const startIndex = slots.findIndex((s) => s.start === selectedStartTime);
  if (startIndex >= 0) {
    const minEndIndex = startIndex + minDuration;
    for (
      let j = minEndIndex + 1; // +1 to skip default minDuration slot
      j < slots.length && j < startIndex + maxDuration + 1;
      j++
    ) {
      if (!slots[j].available) break;
      endTimeOptions.push(slots[j]);
    }
  }

  const canProceed = selectedDate && selectedStartTime && selectedEndTime;

  // Compute first valid end slot based on minDuration
  const getFirstValidEnd = (): string => {
    const startIdx = slots.findIndex((s) => s.start === selectedStartTime);
    if (startIdx < 0 || startIdx + minDuration > slots.length) return "";
    const candidate = slots[startIdx + minDuration - 1];
    return candidate?.available ? candidate.end : "";
  };

  return (
    <div className="sb-step sb-step-2">
      <h2 className="sb-step__title">Pick Your Date & Time</h2>

      {/* Date picker */}
      <div className="sb-field">
        <label className="sb-label" htmlFor="sb-date">
          Date
        </label>
        <input
          id="sb-date"
          type="date"
          className="sb-input"
          min={today}
          value={selectedDate}
          onChange={(e) => setDate(e.target.value)}
        />
      </div>

      {loading && <div className="sb-loading">Checking availability…</div>}
      {error && <div className="sb-error">{error}</div>}

      {!loading && selectedDate && slots.length > 0 && (
        <>
          {hasFixedSlots ? (
            /* FIXED SLOTS MODE: Card list */
            <div className="sb-field">
              <label className="sb-label">Available Time Slots</label>
              <div
                className="sb-slot-list"
                style={{
                  display: "flex",
                  flexDirection: "column",
                  gap: "12px",
                }}
              >
                {slots.map((slot) => (
                  <button
                    key={slot.slot_id || slot.start}
                    className={`sb-slot sb-slot--card ${!slot.available ? "sb-slot--invalid" : ""} ${selectedStartTime === slot.start ? "sb-slot--selected" : ""}`}
                    onClick={() => selectFixedSlot(slot)}
                    disabled={!slot.available}
                    style={{
                      padding: "16px",
                      borderRadius: "8px",
                      textAlign: "left",
                      display: "flex",
                      justifyContent: "space-between",
                      alignItems: "center",
                    }}
                  >
                    <div>
                      <div style={{ fontWeight: "600", fontSize: "16px" }}>
                        {formatTimeTo12Hour(slot.start)} -{" "}
                        {formatTimeTo12Hour(slot.end)}
                      </div>
                      <div
                        style={{
                          color: slot.override_price
                            ? "var(--sb-price)"
                            : "var(--sb-muted)",
                          fontSize: "14px",
                        }}
                      >
                        Duration:{" "}
                        {timeToMinutes(slot.end) - timeToMinutes(slot.start)}min
                        {slot.override_price && (
                          <span
                            style={{ marginLeft: "12px", fontWeight: "600" }}
                          >
                            ${slot.override_price}
                          </span>
                        )}
                      </div>
                    </div>

                    <div
                      style={{
                        display: "flex",
                        flexDirection: "column",
                        justifyContent: "center",
                        alignItems: "center",
                        textDecoration: "normal",
                      }}
                    >
                      <div
                        style={{
                          fontSize: "12px",
                          padding: "4px 8px",
                          borderRadius: "4px",
                          background: slot.has_pending
                            ? "#fff3cd"
                            : slot.available
                              ? "#d4edda"
                              : "#f8d7da",
                          color: slot.has_pending
                            ? "#856404"
                            : slot.available
                              ? "#155724"
                              : "#721c24",
                        }}
                      >
                        {slot.has_pending
                          ? "Pending"
                          : slot.available
                            ? "Available"
                            : "Booked"}
                      </div>
                      {slot.has_pending && (
                        <div
                          style={{
                            fontSize: "11px",
                            color: "#856404",
                            marginTop: "4px",
                          }}
                        >
                          Someone is currently booking this slot
                        </div>
                      )}
                    </div>
                  </button>
                ))}
              </div>
            </div>
          ) : (
            /* LEGACY DYNAMIC GRID MODE */
            <>
              {/* Start time grid */}
              <div className="sb-field">
                <label className="sb-label">Start Time</label>
                <div className="sb-slot-grid">
                  {slots.map((slot, i) => {
                    const validStart = isStartValid(i);
                    const isDisabled = !validStart || !slot.available;
                    return (
                      <button
                        key={slot.start}
                        className={`sb-slot ${isDisabled ? "sb-slot--invalid" : ""} ${selectedStartTime === slot.start ? "sb-slot--selected" : ""} ${slot.has_pending ? "sb-slot--pending" : ""}`}
                        onClick={
                          validStart && slot.available
                            ? () => setStartTime(slot.start)
                            : undefined
                        }
                        title={
                          slot.has_pending
                            ? "Someone is currently booking this slot"
                            : undefined
                        }
                      >
                        {formatTimeTo12Hour(slot.start)}
                        {slot.has_pending && (
                          <span
                            style={{
                              fontSize: "9px",
                              display: "block",
                              color: "#856404",
                            }}
                          >
                            Pending
                          </span>
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* End time */}
              {selectedStartTime && (
                <div className="sb-field">
                  <label className="sb-label" htmlFor="sb-end-time">
                    End Time
                  </label>
                  <select
                    id="sb-end-time"
                    className="sb-input"
                    value={selectedEndTime}
                    onChange={(e) => setEndTime(e.target.value)}
                  >
                    {getFirstValidEnd() && (
                      <option key="default" value={getFirstValidEnd()}>
                        {minDuration}h ({formatTimeTo12Hour(getFirstValidEnd())}
                        )
                      </option>
                    )}
                    {endTimeOptions.map((slot) => {
                      const hours = Math.round(
                        (timeToMinutes(slot.end) -
                          timeToMinutes(selectedStartTime)) /
                          60,
                      );
                      const tooShort = hours < minDuration;
                      const tooLong = hours > maxDuration;
                      return (
                        <option
                          key={slot.end}
                          value={slot.end}
                          disabled={tooShort || tooLong}
                        >
                          {hours}h ({formatTimeTo12Hour(slot.end)})
                        </option>
                      );
                    })}
                  </select>
                </div>
              )}
            </>
          )}
        </>
      )}

      {!loading && selectedDate && slots.length === 0 && (
        <div className="sb-empty">
          {blockers && blockers.length > 0 ? (
            <>
              <p style={{ fontWeight: 600, marginBottom: "8px" }}>
                No availability for this date.
              </p>
              <p style={{ marginBottom: "4px" }}>
                {blockers.length === 1 ? (
                  // Single blocker - direct message
                  <span>
                    <strong>{blockers[0].title}</strong> is not available.
                    Please choose a different date or space.
                  </span>
                ) : (
                  // Multiple blockers - list them
                  <span>
                    {blockers.map((b, i) => (
                      <span key={b.id}>
                        {i > 0 && i === blockers.length - 1
                          ? " and "
                          : i > 0
                            ? ", "
                            : ""}
                        <strong>{b.title}</strong>
                      </span>
                    ))}{" "}
                    are not available. Please choose a different date or spaces.
                  </span>
                )}
              </p>
              <p
                style={{
                  fontSize: "14px",
                  color: "var(--sb-muted)",
                  marginTop: "8px",
                }}
              >
                Please choose a different date or different spaces.
              </p>
            </>
          ) : apiMessage ? (
            <p>{apiMessage}</p>
          ) : (
            <p>No availability for this date. Please choose another day.</p>
          )}
        </div>
      )}

      <div className="sb-step__actions">
        <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
          ← Back
        </button>
        <button
          className="sb-btn sb-btn--primary"
          disabled={!canProceed}
          onClick={nextStep}
        >
          Continue →
        </button>
      </div>
    </div>
  );
}
