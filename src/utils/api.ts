import type {
  AvailabilityResponse,
  BookingCreateResponse,
  Extra,
  Package,
  PriceBreakdownItem,
  PricingResponse,
  SelectedExtra,
  Space,
} from "@/types";

const BASE = () => window.sbConfig.apiBase;
const NONCE = () => window.sbConfig.nonce;

async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const url = `${BASE()}${path}`;
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    "X-WP-Nonce": NONCE(),
    ...(options.headers as Record<string, string>),
  };

  const res = await fetch(url, { ...options, headers });

  if (!res.ok) {
    const err = await res.json().catch(() => ({ message: res.statusText }));
    throw new Error((err as { message?: string }).message ?? res.statusText);
  }

  return res.json() as Promise<T>;
}

// ── Spaces & Packages ─────────────────────────────────────────────────────────

export const fetchSpaces = () => apiFetch<Space[]>("/spaces");

export const fetchSpace = (id: number) => apiFetch<Space>(`/spaces/${id}`);

export const fetchPackages = () => apiFetch<Package[]>("/packages");

// ── Availability ──────────────────────────────────────────────────────────────

export const fetchAvailability = (primarySpaceId: number, date: string) =>
  apiFetch<AvailabilityResponse>(
    `/availability?space_id=${primarySpaceId}&date=${date}`,
  );

// NEW: Multi-space availability with intersection check
// PHASE 3: Add debug log to verify full resource group is sent
export const fetchMultiAvailability = (spaceIds: number[], date: string) => {
  console.log("PHASE 3 CHECK - Sending Group:", spaceIds);
  const qs = new URLSearchParams();
  qs.set("date", date);
  spaceIds.forEach((id) => qs.append("space_ids[]", String(id)));
  return apiFetch<AvailabilityResponse>(`/availability/multi?${qs.toString()}`);
};

// ── Extras ────────────────────────────────────────────────────────────────────

export const fetchExtras = (
  spaceId: number,
  date: string,
  startTime: string,
  endTime: string,
) =>
  apiFetch<Extra[]>(
    `/extras?space_id=${spaceId}&date=${date}&start_time=${startTime}&end_time=${endTime}`,
  );

// Fetch all extras (no filters - for package card display)
export const fetchAllExtras = () =>
  apiFetch<Extra[]>('/extras/all');

// ── Pricing ───────────────────────────────────────────────────────────────────

export const fetchPricing = (params: {
  space_id: number;
  item_ids: number[];
  date: string;
  start_time: string;
  end_time: string;
  extras?: SelectedExtra[];
  package_id?: number;
  slot_id?: string;
}) => {
  const qs = new URLSearchParams();
  qs.set("space_id", String(params.space_id));
  params.item_ids.forEach((id) => qs.append("item_ids[]", String(id)));
  qs.set("date", params.date);
  qs.set("start_time", params.start_time);
  qs.set("end_time", params.end_time);
  if (params.package_id) qs.set("package_id", String(params.package_id));
  if (params.slot_id) qs.set("slot_id", params.slot_id);
  (params.extras ?? []).forEach((e, i) => {
    qs.set(`extras[${i}][extra_id]`, String(e.extra_id));
    qs.set(`extras[${i}][quantity]`, String(e.quantity));
  });
  return apiFetch<PricingResponse>(`/pricing?${qs.toString()}`);
};

// ── Create Booking ────────────────────────────────────────────────────────────

export const createBooking = (payload: {
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
  extras?: SelectedExtra[];
  price_breakdown?: PriceBreakdownItem[];
}) =>
  apiFetch<BookingCreateResponse>("/bookings", {
    method: "POST",
    body: JSON.stringify(payload),
  });

// ── Customer Lookup ───────────────────────────────────────────────────────────

export const sendMagicLink = (email: string) =>
  apiFetch<{ message: string }>("/customer/lookup", {
    method: "POST",
    body: JSON.stringify({ email }),
  });

export const fetchCustomerBookings = (token: string) =>
  apiFetch<{ email: string; bookings: unknown[] }>(
    `/customer/bookings?token=${token}`,
  );

// ── Cart Check ─────────────────────────────────────────────────────────────

export const checkCartHasBooking = () =>
  apiFetch<{ hasCartBooking: boolean }>("/cart/has-booking");

import type { ResourceFootprint } from "@/types";

export const fetchConflicts = (itemId: number, type: "space" | "package") =>
  apiFetch<{ conflict_group_ids: number[] }>("/conflicts", {
    method: "POST",
    body: JSON.stringify({ item_id: itemId, type }),
  }).then((data) => data.conflict_group_ids);

export const fetchResourceMap = (): Promise<
  Record<number, ResourceFootprint>
> => apiFetch("/resource-map");
