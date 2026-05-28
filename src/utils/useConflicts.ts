import { useEffect, useState } from "react";
import { useBookingStore } from "@/store/bookingStore";
import { getLockedResourceIds } from "./conflicts";
import type { SelectionItem } from "@/types";

export function useConflicts() {
  const selectedItems = useBookingStore((state) => state.selectedItems);
  const [lockedResourceIds, setLockedResourceIds] = useState<number[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getLockedResourceIds(selectedItems)
      .then((ids) => {
        if (!cancelled) {
          setLockedResourceIds(ids);
          setLoading(false);
        }
      })
      .catch((e) => {
        console.error("Conflicts fetch failed:", e);
        setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [selectedItems]);

  return { lockedResourceIds, loading };
}
