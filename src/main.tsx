import React from "react";
import { createRoot } from "react-dom/client";
import { BookingApp } from "./components/BookingApp";
import "./styles.css";

const container = document.getElementById("sb-booking-app");

if (container) {
  // Pre-select space or package from shortcode data attributes
  const spaceId = container.dataset.spaceId;
  const packageId = container.dataset.packageId;

  const root = createRoot(container);
  root.render(
    <React.StrictMode>
      <BookingApp
        preSpaceId={spaceId ? Number(spaceId) : undefined}
        prePackageId={packageId ? Number(packageId) : undefined}
      />
    </React.StrictMode>,
  );
}
