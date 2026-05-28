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
    const footprint = await resolvePhysicalFootprint(item);
    footprint.forEach((id) => locked.add(id));
  }
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
export function getTooltip(lockedId: number, _candidate: SelectionItem): string {
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
  return data.conflict_group_ids || [];
}
