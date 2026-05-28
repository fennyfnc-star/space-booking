import React, { useEffect } from "react";
import { useBookingStore } from "@/store/bookingStore";
import type { SelectionItem } from "@/types";
import { fetchSpace, fetchPackages, checkCartHasBooking } from "@/utils/api";
import { StepProgress } from "./shared/StepProgress";
import { Step1Selection } from "./steps/Step1Selection";
import { Step2Scheduling } from "./steps/Step2Scheduling";
import { Step3Addons } from "./steps/Step3Addons";
// Step4Details removed - customer details not required
import { Step5Terms } from "./steps/Step5Terms";
import { Step6Payment } from "./steps/Step6Payment";
import { Step7Confirmation } from "./steps/Step7Confirmation";

interface Props {
  preSpaceId?: number;
  prePackageId?: number;
}

export function BookingApp({ preSpaceId, prePackageId }: Props) {
  const { setStep, bookingId, loadBookingStatus, loadResourceMap } =
    useBookingStore((state) => state);
  const currentStep = useBookingStore((s) => s.currentStep);

  // Direct booking confirmation from query params or data attrs (Step 1/1)
  useEffect(() => {
    const appEl = document.getElementById(
      "sb-booking-app",
    ) as HTMLElement | null;
    const urlParams = new URLSearchParams(window.location.search);

    const directBookingId =
      appEl?.dataset.bookingId ||
      urlParams.get("booking_id") ||
      urlParams.get("id");

    if (directBookingId) {
      const id = parseInt(directBookingId);
      if (!isNaN(id)) {
        useBookingStore.setState({ bookingId: id, currentStep: 6 });
        loadBookingStatus(id);
        return; // Skip other init logic
      }
    }
  }, []);

  // Cart/session check + cleanup
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get("step") === "6" && bookingId) {
      return; // Direct confirmation
    }

    const initCartCheck = async () => {
      try {
        const res = await checkCartHasBooking();
        if (res.hasCartBooking) {
          window.location.href = "/checkout/";
          return;
        } else {
          // Cart empty, no persisted state to clear
        }
      } catch (e) {
        console.error("Cart check on app init failed:", e);
        // Assume empty on error, no persisted state to clear
      }
    };

    initCartCheck();
    loadResourceMap();
  }, []);

  // Pre-select space or package from shortcode attributes
  useEffect(() => {
    if (preSpaceId) {
      fetchSpace(preSpaceId)
        .then((space) => {
          useBookingStore.getState().addItem({
            ...space,
            type: "space" as const,
          } as SelectionItem);
          setStep(2);
        })
        .catch(() => {
          /* ignore */
        });
    } else if (prePackageId) {
      fetchPackages()
        .then((pkgs) => {
          const pkg = pkgs.find((p) => p.id === prePackageId);
          if (pkg) {
            useBookingStore.getState().addItem({
              ...pkg,
              type: "package" as const,
            } as SelectionItem);
            setStep(2);
          }
        })
        .catch(() => {
          /* ignore */
        });
    }
  }, [preSpaceId, prePackageId]);

  return (
    <div className="sb-app">
      <StepProgress currentStep={currentStep} />

      <div className="sb-step-container">
        {currentStep === 1 && <Step1Selection />}
        {currentStep === 2 && <Step2Scheduling />}
        {currentStep === 3 && <Step3Addons />}
        {currentStep === 4 && <Step5Terms />}
        {currentStep === 5 && <Step6Payment />}
        {currentStep === 6 && <Step7Confirmation />}
      </div>
    </div>
  );
}
