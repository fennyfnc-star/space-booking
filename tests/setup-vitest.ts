import "@testing-library/jest-dom/vitest";
import { vi } from "vitest";

// Mock window.sbConfig for tests
Object.defineProperty(window, "sbConfig", {
  value: {
    apiBase: "/wp-json/space-booking/v1",
    nonce: "test-nonce",
    symbol: "$",
  },
  writable: true,
});

// Mock fetch for API calls
global.fetch = vi.fn();
