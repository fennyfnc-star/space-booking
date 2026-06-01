import type { Extra } from "@/types";
import type { MergedExtra } from "@/store/bookingStore";

/**
 * Enriched breakdown item for price preview
 */
export interface EnrichedBreakdownItem {
  label: string;
  amount: number;
}

/**
 * Props for ExtraCard component
 */
export interface ExtraCardProps {
  extra: Extra;
  merged: MergedExtra | undefined;
  packageTitle: string | null;
  isSelected: boolean;
  onIncrement: () => void;
  onDecrement: () => void;
  onRemove: () => void;
}
