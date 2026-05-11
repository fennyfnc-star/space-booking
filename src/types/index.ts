// ── Domain types ──────────────────────────────────────────────────────────────

export interface ResourceFootprint {
  id: number;
  type: "space" | "package";
  footprint: number[];
}

export type SelectionItem =
  | (Space & { type: "space"; physicalSpaceIds?: number[] })
  | (Package & {
      type: "package";
      space_ids?: number[];
      physicalSpaceIds?: number[];
    });

export interface Space {
  id: number;
  title: string;
  description: string;
  excerpt: string;
  thumbnail: string | null;
  hourly_rate: number;
  min_duration: number;
  max_duration: number;
  capacity: number;
  day_overrides: Record<number, DayOverride>;
  price_overrides: Array<{
    days: number[];
    start_time: string;
    end_time: string;
    hourly_rate: number;
  }> | null;
  gallery: string[];
  physicalSpaceIds?: number[]; // Cached footprint self + deps
}

export interface DayOverride {
  open?: string;
  close?: string;
  closed?: boolean;
}

export interface Package {
  id: number;
  title: string;
  description: string;
  thumbnail: string | null;
  price: number;
  duration: number;
  space_id: number;
  space_name: string | null;
  extra_ids: number[];
  space_ids?: number[]; // Multi-spaces for packages
  physicalSpaceIds?: number[]; // Cached footprint
}

export interface Extra {
  id: number;
  title: string;
  description: string;
  price: number;
  inventory: number;
  booked_qty: number;
  available_qty: number;
  is_available: boolean;
  unavailable_reason: "sold_out" | "space_override" | null;
  thumbnail: string | null;
}

export interface TimeSlot {
  start: string; // "H:i"
  end: string;
  available: boolean;
  slot_id?: string;
  override_price?: number;
  pre_buffer?: number;
  post_buffer?: number;
  has_pending?: boolean; // true if slot has a pending (unpaid) booking that will expire
}

// NEW: Blocker info for multi-space availability
export interface BlockerInfo {
  id: number;
  title: string;
  reason: "fully_booked" | "limited_availability";
}

export interface AvailabilityResponse {
  date: string;
  space_id?: number; // For single space
  space_ids?: number[]; // For multi-space
  open_time: string | null;
  close_time: string | null;
  slots: TimeSlot[];
  has_fixed_slots: boolean;
  is_fixed_slots: boolean;
  message?: string;
  // NEW: Multi-space fields
  is_multi?: boolean;
  is_intersection?: boolean;
  blockers?: BlockerInfo[];
}

export interface PriceBreakdownItem {
  label: string;
  amount: number;
  context?: {
    type: "space" | "package" | "extra" | "segment" | "modifier";
    name?: string;
    id?: number;
  };
}

export interface PricingItemDetail {
  id: number;
  type: "sb_space" | "sb_package";
  title: string;
  subtotal: number;
  breakdown: PriceBreakdownItem[];
}

export interface PricingResponse {
  base_price: number;
  modifier_price?: number;
  extras_price: number;
  total_price: number;
  duration_hours: number;
  breakdown: PriceBreakdownItem[];
  items?: PricingItemDetail[];
  extras_breakdown?: PriceBreakdownItem[];
  extras_details?: ExtraDetail[];
}

/**
 * Extra detail from pricing calculation (includes included/paid split)
 */
export interface ExtraDetail {
  extra_id: number;
  title: string;
  total_qty: number;
  included_qty: number;
  paid_qty: number;
  unit_price: number;
  is_locked: boolean;
}

export interface BookingCreateResponse {
  booking_id: number;
  checkout_url: string;
  total_price: number;
  breakdown: PriceBreakdownItem[];
  cart_added_directly?: boolean;
}

declare module "@/utils/api" {
  interface BookingPayload {
    space_id: number;
    package_id?: number;
    selected_item_ids: number[];
    date: string;
    start_time: string;
    end_time: string;
    customer_name: string;
    customer_email: string;
    customer_phone?: string;
    notes?: string;
    marketing_source?: string;
    extras?: SelectedExtra[];
  }
}

// Marketing source options
export type MarketingSource =
  | "Social Media (Instagram/Facebook)"
  | "Google Search"
  | "Word of Mouth / Friend"
  | "Local Signage / Passing by"
  | "Other";

export interface CustomerBooking {
  id: number;
  space_id: number;
  space_name: string;
  thumbnail: string | null;
  booking_date: string;
  start_time: string;
  end_time: string;
  duration_hours: number;
  total_price: number;
  status: "pending" | "confirmed" | "cancelled" | "refunded";
  customer_name: string;
  customer_email: string;
  extras: Array<{ extra_name: string; quantity: number; unit_price: number }>;
}

// ── Global WP config injected via wp_localize_script ─────────────────────────

declare global {
  interface Window {
    sbConfig: {
      apiBase: string;
      nonce: string;
      stripeKey: string;
      currency: string;
      symbol: string;
      dateFormat: string;
      bookingPolicy: string;
    };
  }
}

// ── Selected extras in booking wizard ────────────────────────────────────────

export interface SelectedExtra {
  extra_id: number;
  quantity: number;
  included?: boolean; // true if included with package (cannot be removed below this qty)
}

// ── Booking wizard step state ─────────────────────────────────────────────────

export type BookingStep = 1 | 2 | 3 | 4 | 5 | 6;

export interface CustomField {
  key: string;
  label: string;
  type: "text" | "email" | "tel" | "textarea" | "checkbox" | "radio" | "select";
  required?: boolean;
  placeholder?: string;
  default?: string;
  options?: string[];
}

export type CustomerValue = string | boolean | string[];

export interface CustomerInfo {
  [key: string]: CustomerValue;
}
