import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  fetchMultiAvailability,
  fetchAvailability,
  fetchSpaces,
  fetchSpace,
  fetchPackages,
  fetchExtras,
  fetchPricing,
  createBooking,
  sendMagicLink,
  fetchCustomerBookings,
  checkCartHasBooking,
  fetchConflicts,
  fetchResourceMap,
} from "../src/utils/api";
import { formatBookingDate } from "../src/utils/date";
import { sanitizeBookingPolicyHtml } from "../src/components/steps/Step4Terms";

// Mock the API response helper
const mockSlots = [
  {
    slot_id: "slot_1",
    start: "09:30",
    end: "11:30",
    available: true,
    has_pending: false,
  },
  {
    slot_id: "slot_2",
    start: "12:30",
    end: "14:30",
    available: true,
    has_pending: false,
  },
  {
    slot_id: "slot_3",
    start: "15:30",
    end: "17:30",
    available: true,
    has_pending: false,
  },
];

const mockSpace = {
  id: 224,
  title: "Test Space",
  description: "A test space",
  excerpt: "Test",
  thumbnail: null,
  hourly_rate: 50,
  min_duration: 1,
  max_duration: 8,
  capacity: 10,
  day_overrides: {},
  price_overrides: null,
  gallery: [],
};

const mockPackage = {
  id: 100,
  title: "Test Package",
  description: "A test package",
  thumbnail: null,
  price: 150,
  duration: 4,
  space_id: 224,
  space_name: "Test Space",
  extra_ids: [1, 2],
};

const mockExtra = {
  id: 1,
  title: "Test Extra",
  description: "An extra",
  price: 25,
  inventory: 10,
  booked_qty: 2,
  available_qty: 8,
  is_available: true,
  unavailable_reason: null,
  thumbnail: null,
};

describe("Availability API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("fetchMultiAvailability", () => {
    it("should fetch multi-space availability", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            date: "2026-05-10",
            space_ids: [223, 10, 224],
            is_multi: true,
            is_intersection: true,
            slots: mockSlots,
            has_fixed_slots: false,
          }),
      });

      const result = await fetchMultiAvailability([223, 10, 224], "2026-05-10");

      expect(result.slots).toHaveLength(3);
      expect(result.is_multi).toBe(true);
      expect(result.space_ids).toEqual([223, 10, 224]);
    });

    it("should return empty slots when no availability", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            date: "2026-05-10",
            space_ids: [223],
            is_multi: true,
            is_intersection: true,
            slots: [],
            has_fixed_slots: true,
            blockers: [
              {
                id: 223,
                title: "Covered Secret Garden",
                reason: "fully_booked",
              },
            ],
          }),
      });

      const result = await fetchMultiAvailability([223], "2026-05-10");

      expect(result.slots).toHaveLength(0);
      expect(result.blockers).toHaveLength(1);
    });

    it("should handle single space as array", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            date: "2026-05-10",
            space_ids: [224],
            is_multi: true,
            is_intersection: false,
            slots: mockSlots,
            has_fixed_slots: false,
          }),
      });

      const result = await fetchMultiAvailability([224], "2026-05-10");

      expect(result.slots).toHaveLength(3);
      expect(result.space_ids).toEqual([224]);
    });

    it("should build correct URL with space_ids parameter", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ slots: [], date: "2026-05-10" }),
      });

      await fetchMultiAvailability([223, 10, 224], "2026-05-10");

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining(
          "space_ids%5B%5D=223&space_ids%5B%5D=10&space_ids%5B%5D=224",
        ),
        expect.any(Object),
      );
    });
  });

  describe("fetchAvailability (single space)", () => {
    it("should fetch single space availability", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            date: "2026-05-10",
            space_id: 224,
            slots: mockSlots,
            has_fixed_slots: false,
          }),
      });

      const result = await fetchAvailability(224, "2026-05-10");

      expect(result.slots).toHaveLength(3);
      expect(result.space_id).toBe(224);
    });
  });
});

describe("Availability Logic - Frontend Behavior", () => {
  it("should use lockedResourceIds for space selection", () => {
    const lockedResourceIds: number[] = [224];

    const spaceIds =
      lockedResourceIds && lockedResourceIds.length > 0
        ? lockedResourceIds
        : [];

    expect(spaceIds).toEqual([224]);
  });

  it("should use multi-space endpoint for any number of spaces", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          date: "2026-05-10",
          space_ids: [224],
          is_multi: true,
          slots: mockSlots,
          has_fixed_slots: false,
        }),
    });

    const result = await fetchMultiAvailability([224], "2026-05-10");

    expect(result.slots).toHaveLength(3);
  });

  it("should handle availability response with fixed slots", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          date: "2026-05-10",
          space_ids: [224],
          is_multi: true,
          slots: [
            {
              slot_id: "slot_1",
              start: "09:30",
              end: "11:30",
              available: true,
              has_pending: false,
            },
            {
              slot_id: "slot_2",
              start: "12:30",
              end: "14:30",
              available: false,
              has_pending: false,
            },
          ],
          has_fixed_slots: true,
          is_fixed_slots: true,
        }),
    });

    const result = await fetchMultiAvailability([224], "2026-05-10");

    expect(result.has_fixed_slots).toBe(true);
    expect(result.slots[0].slot_id).toBe("slot_1");
    expect(result.slots[1].available).toBe(false);
  });
});

describe("API Response Handling", () => {
  it("should handle blockers in response", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          date: "2026-05-10",
          space_ids: [223, 10],
          is_multi: true,
          is_intersection: true,
          slots: [],
          has_fixed_slots: false,
          blockers: [
            {
              id: 223,
              title: "Covered Secret Garden",
              reason: "limited_availability",
            },
            { id: 10, title: "Main Cafe", reason: "fully_booked" },
          ],
          message:
            "There is no available time slot for the selected spaces. Reason: Covered Secret Garden and Main Cafe are currently booked.",
        }),
    });

    const result = await fetchMultiAvailability([223, 10], "2026-05-10");

    expect(result.blockers).toHaveLength(2);
    expect(result.message).toContain("Covered Secret Garden");
    expect(result.message).toContain("Main Cafe");
  });
});

// ============================================================================
// Spaces & Packages API
// ============================================================================

describe("Spaces & Packages API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("fetchSpaces", () => {
    it("should fetch all spaces", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([mockSpace, { ...mockSpace, id: 225 }]),
      });

      const result = await fetchSpaces();

      expect(result).toHaveLength(2);
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("/spaces"),
        expect.any(Object),
      );
    });
  });

  describe("fetchSpace", () => {
    it("should fetch single space by ID", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(mockSpace),
      });

      const result = await fetchSpace(224);

      expect(result.id).toBe(224);
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("/spaces/224"),
        expect.any(Object),
      );
    });
  });

  describe("fetchPackages", () => {
    it("should fetch all packages", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([mockPackage]),
      });

      const result = await fetchPackages();

      expect(result).toHaveLength(1);
      expect(result[0].title).toBe("Test Package");
    });
  });
});

// ============================================================================
// Extras API
// ============================================================================

describe("Extras API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("fetchExtras", () => {
    it("should fetch extras for a space and time slot", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([mockExtra]),
      });

      const result = await fetchExtras(224, "2026-05-10", "10:00", "12:00");

      expect(result).toHaveLength(1);
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("space_id=224"),
        expect.any(Object),
      );
    });

    it("should include date and time parameters in URL", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
      });

      await fetchExtras(224, "2026-05-10", "10:00", "12:00");

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("date=2026-05-10"),
        expect.any(Object),
      );
    });
  });
});

// ============================================================================
// Pricing API
// ============================================================================

describe("Pricing API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("fetchPricing", () => {
    it("should fetch pricing for single space", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            base_price: 100,
            extras_price: 0,
            total_price: 100,
            duration_hours: 2,
            breakdown: [],
          }),
      });

      const result = await fetchPricing({
        space_id: 224,
        item_ids: [224],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "12:00",
      });

      expect(result.base_price).toBe(100);
      expect(result.duration_hours).toBe(2);
    });

    it("should include extras in pricing request", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            base_price: 100,
            extras_price: 50,
            total_price: 150,
            duration_hours: 2,
            breakdown: [],
            extras_breakdown: [],
          }),
      });

      const result = await fetchPricing({
        space_id: 224,
        item_ids: [224],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "12:00",
        extras: [
          { extra_id: 1, quantity: 2 },
          { extra_id: 2, quantity: 1 },
        ],
      });

      expect(result.extras_price).toBe(50);
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("extras"),
        expect.any(Object),
      );
    });

    it("should include package_id when provided", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            base_price: 150,
            extras_price: 0,
            total_price: 150,
            duration_hours: 4,
            breakdown: [],
          }),
      });

      await fetchPricing({
        space_id: 224,
        item_ids: [],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "14:00",
        package_id: 100,
      });

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("package_id=100"),
        expect.any(Object),
      );
    });

    it("should include slot_id when provided", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            base_price: 100,
            extras_price: 0,
            total_price: 100,
            duration_hours: 2,
            breakdown: [],
          }),
      });

      await fetchPricing({
        space_id: 224,
        item_ids: [224],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "12:00",
        slot_id: "slot_123",
      });

      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("slot_id=slot_123"),
        expect.any(Object),
      );
    });
  });
});

// ============================================================================
// Booking API
// ============================================================================

describe("Booking API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("createBooking", () => {
    it("should create a new booking", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            booking_id: 123,
            checkout_url: "/checkout/123",
            total_price: 150,
            breakdown: [],
          }),
      });

      const result = await createBooking({
        space_id: 224,
        selected_item_ids: [224],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "12:00",
        customer_name: "John Doe",
        customer_email: "john@example.com",
        customer_phone: "555-1234",
        notes: "Test booking",
      });

      expect(result.booking_id).toBe(123);
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("/bookings"),
        expect.objectContaining({
          method: "POST",
        }),
      );
    });

    it("should include all customer fields", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            booking_id: 124,
            checkout_url: "/checkout/124",
            total_price: 100,
            breakdown: [],
          }),
      });

      await createBooking({
        space_id: 224,
        selected_item_ids: [224],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "12:00",
        customer_name: "Jane Doe",
        customer_email: "jane@example.com",
        customer_phone: "555-5678",
      });

      expect(global.fetch).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          method: "POST",
          body: expect.stringContaining("Jane Doe"),
        }),
      );
    });

    it("should include price breakdown when provided", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            booking_id: 125,
            checkout_url: "/checkout/125",
            total_price: 200,
            breakdown: [
              { label: "Space Rental", amount: 100 },
              { label: "Extras", amount: 50 },
              { label: "Tax", amount: 50 },
            ],
          }),
      });

      const result = await createBooking({
        space_id: 224,
        selected_item_ids: [224],
        date: "2026-05-10",
        start_time: "10:00",
        end_time: "12:00",
        customer_name: "Test User",
        customer_email: "test@example.com",
        price_breakdown: [
          { label: "Space Rental", amount: 100 },
          { label: "Extras", amount: 50 },
          { label: "Tax", amount: 50 },
        ],
      });

      expect(result.breakdown).toHaveLength(3);
    });
  });
});

// ============================================================================
// Customer Lookup API
// ============================================================================

describe("Customer Lookup API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("sendMagicLink", () => {
    it("should send magic link for email", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ message: "Magic link sent" }),
      });

      const result = await sendMagicLink("customer@example.com");

      expect(result.message).toBe("Magic link sent");
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("/customer/lookup"),
        expect.objectContaining({
          method: "POST",
          body: expect.stringContaining("customer@example.com"),
        }),
      );
    });
  });

  describe("fetchCustomerBookings", () => {
    it("should fetch customer bookings with token", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            email: "customer@example.com",
            bookings: [
              {
                id: 1,
                space_id: 224,
                booking_date: "2026-05-10",
                start_time: "10:00",
                end_time: "12:00",
              },
            ],
          }),
      });

      const result = await fetchCustomerBookings("abc123");

      expect(result.email).toBe("customer@example.com");
      expect(result.bookings).toHaveLength(1);
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining("token=abc123"),
        expect.any(Object),
      );
    });
  });
});

// ============================================================================
// Cart API
// ============================================================================

describe("Cart API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("checkCartHasBooking", () => {
    it("should check if cart has booking", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ hasCartBooking: false }),
      });

      const result = await checkCartHasBooking();

      expect(result.hasCartBooking).toBe(false);
    });

    it("should return true when cart has booking", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ hasCartBooking: true }),
      });

      const result = await checkCartHasBooking();

      expect(result.hasCartBooking).toBe(true);
    });
  });
});

// ============================================================================
// Conflicts API
// ============================================================================

describe("Conflicts API", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("fetchConflicts", () => {
    it("should fetch conflict group IDs for space", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ conflict_group_ids: [224, 225, 226] }),
      });

      const result = await fetchConflicts(224, "space");

      expect(result).toHaveLength(3);
    });

    it("should fetch conflict group IDs for package", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ conflict_group_ids: [100, 224] }),
      });

      const result = await fetchConflicts(100, "package");

      expect(result).toHaveLength(2);
    });
  });

  describe("fetchResourceMap", () => {
    it("should fetch resource map", async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            224: { id: 224, type: "space", footprint: [224] },
            100: { id: 100, type: "package", footprint: [224, 225] },
          }),
      });

      const result = await fetchResourceMap();

      expect(result[224]).toBeDefined();
      expect(result[224].type).toBe("space");
    });
  });
});

// ============================================================================
// API Error Handling
// ============================================================================

describe("API Error Handling", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("should throw error on non-ok response", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 404,
      statusText: "Not Found",
      json: () => Promise.resolve({ message: "Resource not found" }),
    });

    await expect(fetchSpace(999)).rejects.toThrow("Resource not found");
  });

  it("should throw error with status text if no JSON message", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      statusText: "Internal Server Error",
      json: () => Promise.reject(new Error("Invalid JSON")),
    });

    await expect(fetchSpaces()).rejects.toThrow("Internal Server Error");
  });
});

describe("formatBookingDate", () => {
  it("formats ISO booking dates in long form", () => {
    expect(formatBookingDate("2026-06-02")).toBe("June 2, 2026");
  });

  it("returns an empty string for empty input", () => {
    expect(formatBookingDate("")).toBe("");
  });

  it("returns the original value for non-ISO input", () => {
    expect(formatBookingDate("June 2, 2026")).toBe("June 2, 2026");
  });
});

describe("booking policy html", () => {
  it("keeps semantic WYSIWYG markup while stripping unsafe tags", () => {
    const html =
      '<h2>Terms</h2><p>Please read the policy.</p><ul><li>Item 1</li><li>Item 2</li></ul><script>alert("xss")</script>';

    const sanitized = sanitizeBookingPolicyHtml(html);

    expect(sanitized).toContain("<h2>Terms</h2>");
    expect(sanitized).toContain("<p>Please read the policy.</p>");
    expect(sanitized).toContain("<ul>");
    expect(sanitized).toContain("<li>Item 1</li>");
    expect(sanitized).toContain("<li>Item 2</li>");
    expect(sanitized).not.toContain("<script>");
  });
});
