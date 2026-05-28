// No backend PHP import needed - use api.ts fetchConflicts
import type { Space, Package } from "../types";

export type SelectionItem =
  | (Space & { type: "space" })
  | (Package & { type: "package" });

export interface LockedResources {
  lockedIds: number[];
  tooltip: string;
}

/**
 * Get all locked space IDs from current selection (union of all footprints)
 */
export async function getLockedResourceIds(
  items: SelectionItem[],
): Promise<number[]> {
  const locked = new Set<number>();
  for (const item of items) {
    console.log("🔒 getLockedResourceIds: item", item.id, item.type);
    const footprint = await resolvePhysicalFootprint(item);
    console.log("🔒 getLockedResourceIds: footprint for", item.id, footprint);
    footprint.forEach((id) => locked.add(id));
  }
  console.log("🔒 getLockedResourceIds: final locked IDs", Array.from(locked));
  return Array.from(locked);
}

/**
 * Resolve full physical footprint for item (recursive deps via API)
 */
export async function resolvePhysicalFootprint(
  item: SelectionItem,
): Promise<number[]> {
  const ids = await fetchConflicts(item.id, item.type);
  return ids;
}

/**
 * Check if candidate conflicts with locked
 */
export function hasConflict(
  lockedIds: number[],
  candidateFootprint: number[],
): boolean {
  return candidateFootprint.some((id) => lockedIds.includes(id));
}

/**
 * Generate tooltip for locked item
 */
export function getTooltip(lockedId: number, candidate: SelectionItem): string {
  return `Conflicts with current selection (contains Space #${lockedId})`;
}

// API helpers (add to api.ts later)
export async function fetchConflicts(
  itemId: number,
  type: "space" | "package",
): Promise<number[]> {
  // POST /api/conflicts {item_id, type} -> {conflict_group_ids}
  const res = await fetch(`${window.sbConfig.apiBase}/conflicts`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": window.sbConfig.nonce,
    },
    body: JSON.stringify({ item_id: itemId, type }),
  });
  const data = await res.json();
  console.log("🔒 fetchConflicts:", itemId, type, "->", data.conflict_group_ids);
  return data.conflict_group_ids || [];
}
