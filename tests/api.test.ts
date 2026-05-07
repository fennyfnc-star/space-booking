import { describe, it, expect, vi, beforeEach } from "vitest";
import { fetchMultiAvailability, fetchAvailability } from "../src/utils/api";

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
