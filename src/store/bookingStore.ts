import { create } from "zustand";
import type {
  BookingStep,
  CustomerInfo,
  CustomField,
  CustomerValue,
  Extra,
  Package,
  PriceBreakdownItem,
  ResourceFootprint,
  SelectedExtra,
  Space,
  SelectionItem,
} from "../types";

import { checkCartHasBooking, fetchResourceMap } from "../utils/api";

// New: Track which spaces are covered by selected packages
interface PackageCoverage {
  packageId: number;
  packageTitle: string;
  coveredSpaceIds: number[];
}

// Helper: Compute locked resource IDs from selected items
const computeLockedResourceIds = (
  items: SelectionItem[],
  resourceMap: Record<number, ResourceFootprint> | null,
): number[] => {
  if (!resourceMap) return [];
  const locked = new Set<number>();
  for (const it of items) {
    const footprint = resourceMap[it.id]?.footprint ?? [it.id];
    footprint.forEach((id) => locked.add(id));
  }
  return Array.from(locked);
};

interface BookingState {
  bookingPolicy: string;
  currentStep: BookingStep;
  selectedItems: SelectionItem[];
  lockedResourceIds: number[]; // Cached union footprint for UI
  resourceMap: Record<number, ResourceFootprint> | null;
  packageCoverage: PackageCoverage[]; // NEW: Track packages and their covered spaces
  selectedDate: string;
  selectedStartTime: string;
  selectedEndTime: string;
  availableExtras: Extra[];
  selectedExtras: SelectedExtra[];
  customerInfo: CustomerInfo;
  customerFields: CustomField[];
  checkoutUrl: string | null;
  bookingId: number | null;
  bookingStatus: "pending" | "in_review" | "error";
  totalPrice: number;
  priceBreakdown: PriceBreakdownItem[];
  extrasDetails: import("@/types").ExtraDetail[]; // From backend pricing response
  isConfirmed: boolean;
  hasCartBooking: boolean;
  setStep: (step: BookingStep) => void;
  nextStep: () => void;
  prevStep: () => void;
  addItem: (item: SelectionItem) => void;
  removeItem: (id: number) => void;
  clearItems: () => void;
  getLockedResourceIds: () => number[];
  loadResourceMap: () => Promise<void>;
  setDate: (date: string) => void;
  setStartTime: (time: string) => void;
  setEndTime: (time: string) => void;
  setAvailableExtras: (extras: Extra[]) => void;
  setSelectedExtras: (extras: SelectedExtra[]) => void;
  setIncludedExtras: (extraIds: number[]) => void;
  toggleItem: (item: Space | Package) => void;
  toggleExtra: (extra_id: number, quantity?: number, included?: boolean) => void;
  incrementExtra: (extra_id: number) => void;
  decrementExtra: (extra_id: number) => void;
  setCustomerField: (key: string, value: CustomerValue) => void;
  setCustomerFields: (fields: CustomField[]) => void;
  fetchCustomerFields: () => Promise<void>;
  validateCustomerInfo: () => boolean;
  setCheckoutData: (data: {
    checkoutUrl: string;
    bookingId: number;
    totalPrice: number;
    breakdown: PriceBreakdownItem[];
  }) => void;
  setPriceBreakdown: (breakdown: PriceBreakdownItem[], total: number, extrasDetails?: import("@/types").ExtraDetail[]) => void;
  confirmBooking: () => void;
  checkCartBooking: () => Promise<void>;
  loadBookingStatus: (id: number) => Promise<void>;
  setBookingStatus: (status: "pending" | "in_review" | "error") => void;
  getPrimarySpaceId: () => number | null;
  getCoveredSpaceIds: () => number[];
  setHasCartBooking: (has: boolean) => void;
  reset: () => void;
  setBookingPolicy: (policy: string) => void;
  getMergedExtras: () => MergedExtra[];
}

// NEW: Type for merged extras (UI adapter)
export interface MergedExtra {
  extra_id: number;
  title: string;
  total_qty: number;
  included_qty: number;
  paid_qty: number;
  unit_price: number;
  is_locked: boolean;
}

const DEFAULT_CUSTOMER: CustomerInfo = {};

export const useBookingStore = create<BookingState>()((set, get) => ({
  // ── Initial state ────────────────────────────────────────────────────────
  currentStep: 1,
  bookingPolicy: "",
  selectedItems: [],
  lockedResourceIds: [],
  resourceMap: null,
  packageCoverage: [], // NEW: Track packages and their covered spaces
  selectedDate: "",
  selectedStartTime: "",
  selectedEndTime: "",
  availableExtras: [],
  selectedExtras: [],
  customerInfo: { ...DEFAULT_CUSTOMER },
  customerFields: [],
  checkoutUrl: null,
  bookingId: null,
  bookingStatus: "pending",
  totalPrice: 0,
  priceBreakdown: [],
  extrasDetails: [],
  isConfirmed: false,
  hasCartBooking: false,

  // ── Navigation ───────────────────────────────────────────────────────────
  setStep: (step: BookingStep) => set({ currentStep: step }),
  nextStep: () =>
    set((state) => {
      let next = state.currentStep + 1;
      return { currentStep: Math.min(next, 6) as BookingStep };
    }),
  prevStep: () =>
    set((state) => {
      // Skip step 4 (Details) - go from step 5 (Terms) directly to step 3 (Add-ons)
      let prev = state.currentStep - 1;
      if (prev === 4) prev = 3;
      return { currentStep: Math.max(prev, 1) as BookingStep };
    }),

  loadResourceMap: async () => {
    console.log("loadResourceMap called");
    try {
      const map = await fetchResourceMap();
      console.log("resourceMap loaded, keys:", Object.keys(map));
      // Log package footprints
      for (const [id, data] of Object.entries(map)) {
        if (data.type === 'package') {
          console.log("  Package", id, "footprint:", data.footprint);
        }
      }
      set({ resourceMap: map });
    } catch (e) {
      console.error("Failed to load resource map:", e);
    }
  },

  // ── Step 1 ───────────────────────────────────────────────────────────────
  addItem: (item: SelectionItem) => {
    console.log("addItem called:", item.id, item.title);
    const state = get();
    console.log(
      "current selectedItems:",
      state.selectedItems.map((i) => i.id),
    );
    console.log("resourceMap loaded?", !!state.resourceMap);
    console.log("current locked:", state.lockedResourceIds);
    if (state.selectedItems.some((i) => i.id === item.id)) {
      console.log("already selected, return");
      return;
    }
    if (!state.resourceMap) {
      console.log("no resourceMap, alert");
      alert("Resource map loading...");
      return;
    }
    const map = state.resourceMap;
    const itemFootprint = map[item.id]?.footprint ?? [item.id];
    console.log("itemFootprint:", itemFootprint);
    const currentLocked = state.lockedResourceIds;
    const hasOverlap = itemFootprint.some((id) => currentLocked.includes(id));
    console.log("hasOverlap?", hasOverlap);
    if (hasOverlap) {
      console.log("overlap, alert");
      alert("Conflicts with current selection: overlaps physical resources.");
      return;
    }
    const newSelected = [...state.selectedItems, item];
    const newLocked = computeLockedResourceIds(newSelected, map);
    console.log(
      "setting new selected:",
      newSelected.map((i) => i.id),
      "new locked:",
      newLocked,
    );
    set({ selectedItems: newSelected, lockedResourceIds: newLocked });
    console.log("addItem done");
  },
  removeItem: (id: number) => {
    console.log("removeItem called:", id);
    const state = get();
    console.log(
      "current selectedItems:",
      state.selectedItems.map((i) => i.id),
    );
    console.log("current locked:", state.lockedResourceIds);
    if (!state.resourceMap) {
      console.log("no resourceMap, return");
      return;
    }
    const map = state.resourceMap;
    const newSelected = state.selectedItems.filter((i) => i.id !== id);
    const newLocked = computeLockedResourceIds(newSelected, map);
    console.log(
      "setting new selected:",
      newSelected.map((i) => i.id),
      "new locked:",
      newLocked,
    );
    set({ selectedItems: newSelected, lockedResourceIds: newLocked });
    console.log("removeItem done");
  },

  // Unified toggle function for cards/checkboxes
  toggleItem: (item: Space | Package) => {
    const targetId = Number(item.id);
    const state = get();
    const isSelected = state.selectedItems.some(
      (i) => Number(i.id) === targetId,
    );

    console.log("🔄 toggleItem called:", targetId, "title:", item.title, "isSelected:", isSelected);
    console.log("  current packageCoverage:", state.packageCoverage);
    console.log("  current selectedItems:", state.selectedItems.map(i => i.id));

// Check if this is a package (has space_ids)
    const isPackage = "space_ids" in item && 
      Array.isArray(item.space_ids) && 
      item.space_ids.length > 0;
    const packageSpaceIds = isPackage ? item.space_ids! : [];
    const itemTitle = item.title || "Item";
    
    console.log("  isPackage:", isPackage, "space_ids:", packageSpaceIds);

    if (isSelected) {
      // REMOVAL - remove from both selectedItems and packageCoverage
      const updatedItems = state.selectedItems.filter(
        (i) => Number(i.id) !== targetId,
      );
      
      // Also remove from packageCoverage if it was a package
      let newPackageCoverage = state.packageCoverage;
      if (isPackage) {
        newPackageCoverage = state.packageCoverage.filter(
          (pc) => pc.packageId !== targetId,
        );
      }

      const newLocked = computeLockedResourceIds(updatedItems, state.resourceMap);
      set({ 
        selectedItems: updatedItems, 
        lockedResourceIds: newLocked,
        packageCoverage: newPackageCoverage 
      });

      console.log(`Unselected: ${targetId}. Re-computing locks...`);
} else {
      // ADDITION - with mutual exclusivity checks
      if (!state.resourceMap) {
        alert("Resource map loading...");
        return;
      }
      const map = state.resourceMap;
      
      // Check 1: If adding a SPACE, check if it's covered by any selected package
      if (!isPackage) {
        const coveredByPackage = state.packageCoverage.find((pc) =>
          pc.coveredSpaceIds.includes(targetId)
        );
        if (coveredByPackage) {
          console.warn(
            `Cannot select this space. It is already included in package "${coveredByPackage.packageTitle}".`,
          );
          alert(
            `Cannot select this space. It is already included in package "${coveredByPackage.packageTitle}". Please unselect the package first if you want this space.`
          );
          return;
        }
      }
      
      // Check 2: If adding a PACKAGE, check if any of its spaces are already selected
      if (isPackage && packageSpaceIds.length > 0) {
        const alreadySelectedSpaces = state.selectedItems.filter((sel) => 
          packageSpaceIds.includes(Number(sel.id))
        );
        if (alreadySelectedSpaces.length > 0) {
          const spaceNames = alreadySelectedSpaces.map((s) => s.title).join(", ");
          console.warn(
            `Cannot select this package. The following spaces are already selected: ${spaceNames}.`,
          );
          alert(
            `Cannot select this package. The following spaces are already selected: ${spaceNames}. Please unselect the space(s) first if you want this package.`
          );
          return;
        }
        
        // Check 3: Check for overlapping packages (packages that share any space)
        const otherPackages = state.selectedItems.filter((sel) => {
          if (sel.type !== "package") return false;
          const otherPkg = sel as Package;
          return "space_ids" in otherPkg && 
            Array.isArray(otherPkg.space_ids) && 
            otherPkg.space_ids.some((sid) => packageSpaceIds.includes(sid));
        });
        if (otherPackages.length > 0) {
          const pkgNames = otherPackages.map((p) => p.title).join(", ");
          console.warn(
            `This package overlaps with already selected package: ${pkgNames}.`,
          );
          alert(
            `This package overlaps with an already selected package: ${pkgNames}. Please unselect the existing package first.`
          );
          return;
        }
      }
      
      // Check 4: Physical resource overlap check (existing)
      const itemFootprint = map[targetId]?.footprint ?? [targetId];
      const hasOverlap = itemFootprint.some((id) =>
        state.lockedResourceIds.includes(id),
      );
      if (hasOverlap) {
        console.warn(
          "Cannot add: Item is physically locked by another selection.",
        );
        alert("Conflicts with current selection: overlaps physical resources.");
        return;
      }
      
      // Add the item
      const typedItem: SelectionItem = (
        isPackage
          ? { ...item, type: "package" as const }
          : { ...item, type: "space" as const }
      ) as SelectionItem;
      const updatedItems = [...state.selectedItems, typedItem];
      
      // Track package coverage
      let newPackageCoverage = state.packageCoverage;
      if (isPackage && packageSpaceIds.length > 0) {
        newPackageCoverage = [
          ...state.packageCoverage,
          {
            packageId: targetId,
            packageTitle: itemTitle,
            coveredSpaceIds: packageSpaceIds,
          },
        ];
        console.log("📦 Added packageCoverage:", newPackageCoverage);
      }

      const newLocked = computeLockedResourceIds(updatedItems, map);
      set({ 
        selectedItems: updatedItems, 
        lockedResourceIds: newLocked,
        packageCoverage: newPackageCoverage 
      });

      console.log(`Selected: ${targetId}. Updating locks...`);
    }
  },
clearItems: () => set({ selectedItems: [], lockedResourceIds: [], packageCoverage: [] }),
  getPrimarySpaceId: () => {
    const state = get();
    if (state.selectedItems.length === 0) return null;
    const item = state.selectedItems[0];
    if (item.type === "space") {
      return Number(item.id);
    }
    if (item.type === "package") {
      const pkg = item as Package;
      if ("space_id" in pkg && pkg.space_id) {
        const resolvedId = Number(pkg.space_id);
        console.log("Package resolved to Space:", resolvedId);
        return resolvedId;
      }
    }
    return Number(item.id); // Fallback
  },
  getCoveredSpaceIds: () => {
    const state = get();
    return state.packageCoverage.flatMap((pc) => pc.coveredSpaceIds);
  },

  // NEW: Merged extras selector - computes included/paid split for UI
  getMergedExtras: (): MergedExtra[] => {
    const state = get();
    const { selectedExtras, availableExtras, packageCoverage } = state;
    
    if (selectedExtras.length === 0) return [];
    
    // Build included_qty map from all selected packages (highest wins)
    const includedQtyMap = new Map<number, number>();
    for (const pkg of packageCoverage) {
      // We need to fetch package extra_ids - for now, use selectedItems
      const pkgItem = state.selectedItems.find(
        (i) => i.type === "package" && Number(i.id) === pkg.packageId,
      );
      if (pkgItem && "extra_ids" in pkgItem && Array.isArray(pkgItem.extra_ids)) {
        for (const extraId of pkgItem.extra_ids) {
          const current = includedQtyMap.get(extraId) ?? 0;
          // Each package includes 1 of each extra by default
          includedQtyMap.set(extraId, Math.max(current, 1));
        }
      }
    }
    
    // Build merged extras array
    const merged: MergedExtra[] = [];
    const extraMap = new Map(availableExtras.map((e) => [e.id, e]));
    
    for (const sel of selectedExtras) {
      const extraInfo = extraMap.get(sel.extra_id);
      const total_qty = sel.quantity;
      const included_qty = includedQtyMap.get(sel.extra_id) ?? (sel.included ? 1 : 0);
      const paid_qty = Math.max(0, total_qty - included_qty);
      
      merged.push({
        extra_id: sel.extra_id,
        title: extraInfo?.title ?? `Extra ${sel.extra_id}`,
        total_qty,
        included_qty,
        paid_qty,
        unit_price: extraInfo?.price ?? 0,
        is_locked: total_qty <= included_qty,
      });
    }
    
    return merged;
  },
  getLockedResourceIds: () => {
    const state = get();
    console.log("getLockedResourceIds CALLED");
    console.log(
      "  selectedItems:",
      state.selectedItems.map((i) => i.id),
    );
    if (!state.resourceMap) {
      console.log("  NO resourceMap, returning []");
      return [];
    }
    const result = computeLockedResourceIds(state.selectedItems, state.resourceMap);
    console.log("  FINAL lockedResourceIds:", result);
    return result;
  },

  setSpace: (space: Space | null) => {
    if (space) {
      get().addItem({
        ...space,
        type: "space" as const,
      });
    } else {
      get().clearItems();
    }
  },
  setPackage: (pkg: Package | null) => {
    if (pkg) {
      get().addItem({
        ...pkg,
        type: "package" as const,
      });
    } else {
      get().clearItems();
    }
  },

  // ── Step 2 ───────────────────────────────────────────────────────────────
  setDate: (date: string) =>
    set({
      selectedDate: date,
      selectedStartTime: "",
      selectedEndTime: "",
      selectedExtras: [],
    }),
  setStartTime: (time: string) =>
    set({
      selectedStartTime: time,
      selectedEndTime: "",
      selectedExtras: [],
    }),
  setEndTime: (time: string) => set({ selectedEndTime: time }),

  // ── Step 3 ───────────────────────────────────────────────────────────────
  setAvailableExtras: (extras: Extra[]) => {
    console.log("📦 STORE setAvailableExtras:", extras.length, "extras");
    set({ availableExtras: extras });
  },

  setSelectedExtras: (extras: SelectedExtra[]) => {
    console.log("📦 STORE setSelectedExtras:", extras.length, "extras");
    set({ selectedExtras: extras });
  },

  toggleExtra: (extra_id: number, quantity: number = 1, included: boolean = false) => {
    const current = get().selectedExtras;
    console.group("🔄 STORE toggleExtra");
    console.log(
      "Before - extra_id:",
      extra_id,
      "current selectedExtras:",
      current.map((e) => e.extra_id),
    );
    const exists = current.find((e) => e.extra_id === extra_id);

    if (exists) {
      // If included, cannot remove completely - just reduce quantity
      if (exists.included) {
        // If trying to remove included extra, reduce to minimum (included qty only)
        const newExtras = current.map((e) =>
          e.extra_id === extra_id ? { ...e, quantity: 1, included: true } : e
        );
        console.log(
          "REDUCE TO INCLUDED - new selectedExtras:",
          newExtras.map((e) => e.extra_id),
        );
        set({ selectedExtras: newExtras });
      } else {
        // Remove completely (non-included)
        const newExtras = current.filter((e) => e.extra_id !== extra_id);
        console.log(
          "REMOVE - new selectedExtras:",
          newExtras.map((e) => e.extra_id),
        );
        set({ selectedExtras: newExtras });
      }
    } else {
      // Add (new extra or re-add included)
      const newExtras = [...current, { extra_id, quantity, included }];
      console.log(
        "ADD - new selectedExtras:",
        newExtras.map((e) => e.extra_id),
      );
      set({ selectedExtras: newExtras });
    }
    console.groupEnd();
  },

  // Increment extra quantity by 1
  incrementExtra: (extra_id: number) => {
    const current = get().selectedExtras;
    const exists = current.find((e) => e.extra_id === extra_id);
    if (exists) {
      const newExtras = current.map((e) =>
        e.extra_id === extra_id ? { ...e, quantity: e.quantity + 1 } : e
      );
      set({ selectedExtras: newExtras });
    } else {
      // Add new with quantity 1
      set({ selectedExtras: [...current, { extra_id, quantity: 1, included: false }] });
    }
  },

  // Decrement extra quantity by 1 (respects included_qty minimum)
  decrementExtra: (extra_id: number) => {
    const current = get().selectedExtras;
    const exists = current.find((e) => e.extra_id === extra_id);
    if (!exists) return;

    // Get included_qty for this extra from packageCoverage
    const { packageCoverage, availableExtras, selectedItems } = get();
    let includedQty = 0;
    
    // Find included_qty from packages
    for (const pkg of packageCoverage) {
      const pkgItem = selectedItems.find(
        (i) => i.type === "package" && Number(i.id) === pkg.packageId
      );
      if (pkgItem && "extra_ids" in pkgItem && Array.isArray(pkgItem.extra_ids)) {
        if (pkgItem.extra_ids.includes(extra_id)) {
          includedQty = Math.max(includedQty, 1);
        }
      }
    }

    // Can't go below included_qty
    if (exists.quantity <= includedQty) {
      // If it's included, reduce to included_qty (which is 1), otherwise stay at minimum
      if (exists.included) {
        const newExtras = current.map((e) =>
          e.extra_id === extra_id ? { ...e, quantity: includedQty || 1, included: true } : e
        );
        set({ selectedExtras: newExtras });
      }
      return;
    }

    // If quantity would go to 0, remove entirely
    if (exists.quantity === 1) {
      const newExtras = current.filter((e) => e.extra_id !== extra_id);
      set({ selectedExtras: newExtras });
    } else {
      const newExtras = current.map((e) =>
        e.extra_id === extra_id ? { ...e, quantity: e.quantity - 1 } : e
      );
      set({ selectedExtras: newExtras });
    }
  },

  // Set included extras from package (auto-added)
  setIncludedExtras: (extraIds: number[]) => {
    const current = get().selectedExtras;
    console.log("🔄 setIncludedExtras:", extraIds);

    // Build new extras array, updating existing entries or adding new ones
    const newExtrasMap = new Map<number, SelectedExtra>();

    // First, add all current extras
    for (const e of current) {
      newExtrasMap.set(e.extra_id, e);
    }

    // Then, update/add included extras
    for (const extraId of extraIds) {
      const exists = newExtrasMap.get(extraId);
      if (!exists) {
        // New extra - add as included
        newExtrasMap.set(extraId, { extra_id: extraId, quantity: 1, included: true });
      } else if (!exists.included) {
        // Update existing non-included to included (keep higher quantity if any)
        newExtrasMap.set(extraId, { ...exists, included: true });
      }
      // If already included, do nothing (keep existing)
    }

    set({ selectedExtras: Array.from(newExtrasMap.values()) });
  },

  // ── Step 4 ───────────────────────────────────────────────────────────────
  setCustomerField: (key: string, value: CustomerValue) =>
    set((state) => ({
      customerInfo: { ...state.customerInfo, [key]: value },
    })),
  setCustomerFields: (fields: CustomField[]) => set({ customerFields: fields }),
  fetchCustomerFields: async () => {
    try {
      const res = await fetch(`${window.sbConfig.apiBase}/customer/fields/`, {
        headers: { "X-WP-Nonce": window.sbConfig.nonce },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      console.log("Customer fields:", data);
      if (data.fields && Array.isArray(data.fields) && data.fields.length > 0) {
        set({ customerFields: data.fields });
        const state = get();
        const newCustomerInfo = { ...state.customerInfo };
        data.fields.forEach((f: CustomField) => {
          if (f.default !== undefined && f.default !== "") {
            newCustomerInfo[f.key] = f.default;
          }
        });
        set({ customerInfo: newCustomerInfo });
      } else {
        console.warn("Empty fields response, using defaults");
        const defaults: CustomField[] = [
          { key: "name", label: "Full Name", type: "text", required: true },
          {
            key: "email",
            label: "Email Address",
            type: "email",
            required: true,
          },
          { key: "phone", label: "Phone", type: "tel", required: false },
          {
            key: "notes",
            label: "Special Requests",
            type: "textarea",
            required: false,
          },
        ];
        set({ customerFields: defaults });
      }
    } catch (e) {
      console.error("Fetch customer fields failed:", e);
      const defaults: CustomField[] = [
        { key: "name", label: "Full Name", type: "text", required: true },
        {
          key: "email",
          label: "Email Address",
          type: "email",
          required: true,
        },
        { key: "phone", label: "Phone", type: "tel", required: false },
        {
          key: "notes",
          label: "Special Requests",
          type: "textarea",
          required: false,
        },
      ];
      set({ customerFields: defaults });
    }
  },
  validateCustomerInfo: (): boolean => {
    const { customerFields, customerInfo } = get();
    return customerFields.every((f) => {
      if (!f.required) return true;
      const val = customerInfo[f.key];
      if (val === "" || val === undefined || val === null) return false;
      if (
        f.type === "email" &&
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val as string)
      )
        return false;
      return true;
    });
  },

  // ── Step 5 ───────────────────────────────────────────────────────────────
  setCheckoutData: ({
    checkoutUrl,
    bookingId,
    totalPrice,
    breakdown,
  }: {
    checkoutUrl: string;
    bookingId: number;
    totalPrice: number;
    breakdown: PriceBreakdownItem[];
  }) => set({ checkoutUrl, bookingId, totalPrice, priceBreakdown: breakdown }),

  setPriceBreakdown: (breakdown: PriceBreakdownItem[], total: number, extrasDetails: import("@/types").ExtraDetail[] = []) => {
    console.group("💰 STORE setPriceBreakdown");
    console.log("Breakdown:", breakdown);
    console.log("Total:", total);
    console.log("ExtrasDetails:", extrasDetails);
    console.groupEnd();
    // Backend provides detailed labels, no enrichment needed
    set({ priceBreakdown: breakdown, totalPrice: total, extrasDetails });
  },

  // ── Step 6 ───────────────────────────────────────────────────────────────
  confirmBooking: () => {
    set({ isConfirmed: true });
    get().reset();
  },

  // ── Reset ────────────────────────────────────────────────────────────────
  setBookingPolicy: (policy: string) => set({ bookingPolicy: policy }),

  // ── Cart ──────────────────────────────────────────────────────────────
  checkCartBooking: async () => {
    try {
      const res = await checkCartHasBooking();
      if (res.hasCartBooking) {
        get().reset();
      } else {
        set({ hasCartBooking: false });
      }
    } catch (e) {
      console.error("Cart check failed:", e);
      set({ hasCartBooking: false });
    }
  },

  setHasCartBooking: (has: boolean) => set({ hasCartBooking: has }),

  // ── Booking Status ───────────────────────────────────────────────────────
  loadBookingStatus: async (id: number) => {
    try {
      const res = await fetch(`${window.sbConfig.apiBase}/bookings/${id}`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      const status = data.status || data.booking?.status || "error";
      set({ bookingStatus: status as "pending" | "in_review" | "error" });
      if (data.booking) {
        // Populate store from booking data if needed
        const b = data.booking;
        set({
          selectedDate: b.booking_date || "",
          selectedStartTime: b.start_time || "",
          selectedEndTime: b.end_time || "",
          totalPrice: parseFloat(b.total_price || "0"),
          customerInfo: {
            name: b.customer_name || "",
            email: b.customer_email || "",
            phone: b.customer_phone || "",
          },
        });
      }
    } catch (e) {
      console.error("loadBookingStatus failed:", e);
      set({ bookingStatus: "error" });
    }
  },

  setBookingStatus: (status: "pending" | "in_review" | "error") =>
    set({ bookingStatus: status }),

reset: () => {
    set({
      currentStep: 1,
      bookingPolicy: "",
      selectedItems: [],
      lockedResourceIds: [],
      resourceMap: null,
      packageCoverage: [],
      selectedDate: "",
      selectedStartTime: "",
      selectedEndTime: "",
      availableExtras: [],
      selectedExtras: [],
      customerInfo: { ...DEFAULT_CUSTOMER },
      customerFields: [],
      checkoutUrl: null,
      bookingId: null,
      bookingStatus: "pending",
      totalPrice: 0,
      priceBreakdown: [],
      isConfirmed: false,
      hasCartBooking: false,
    });
  },
}));
