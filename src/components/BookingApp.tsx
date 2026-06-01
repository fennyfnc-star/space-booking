import { useEffect, useState } from "react";
import { useBookingStore } from "@/store/bookingStore";
import type { SelectionItem } from "@/types";
import { fetchSpace, fetchPackages, checkCartHasBooking, clearCartBooking } from "@/utils/api";
import { StepProgress } from "./shared/StepProgress";
import { Step1Selection } from "./steps/Step1Selection";
import { Step2Scheduling } from "./steps/Step2Scheduling";
import { Step3Addons } from "./steps/Step3Addons";
import { Step4PackageQuestions } from "./steps/Step4PackageQuestions";
import { Step4Terms } from "./steps/Step4Terms";
import { Step5Payment } from "./steps/Step5Payment";
import { Step6Confirmation } from "./steps/Step6Confirmation";

interface Props {
  preSpaceId?: number;
  prePackageId?: number;
}

export function BookingApp({ preSpaceId, prePackageId }: Props) {
  const { setStep, bookingId, loadBookingStatus, loadResourceMap, hasPackageQuestionsStep } =
    useBookingStore((state) => state);
  const currentStep = useBookingStore((s) => s.currentStep);
  const [isCartGateLoading, setIsCartGateLoading] = useState(true);
  const [hasCartBookingGate, setHasCartBookingGate] = useState(false);
  const [isClearingCart, setIsClearingCart] = useState(false);

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
        useBookingStore.setState({ bookingId: id, currentStep: 7 });
        loadBookingStatus(id);
        return; // Skip other init logic
      }
    }
  }, []);

  // Cart/session check + cleanup
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get("step") === "7" && bookingId) {
      setIsCartGateLoading(false);
      return; // Direct confirmation
    }

    const initCartCheck = async () => {
      try {
        const res = await checkCartHasBooking();
        if (res.hasCartBooking) {
          setHasCartBookingGate(true);
          return;
        }
      } catch (e) {
        // Assume empty on error.
      } finally {
        setIsCartGateLoading(false);
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

  const checkoutUrl = window.sbConfig.checkoutUrl || "/checkout/";

  const handleGoToCheckout = () => {
    window.location.href = checkoutUrl;
  };

  const handleClearCartBooking = async () => {
    setIsClearingCart(true);
    try {
      await clearCartBooking();
      setHasCartBookingGate(false);
    } catch {
      // Keep gate shown if clear fails.
    } finally {
      setIsClearingCart(false);
    }
  };

  if (isCartGateLoading) {
    return (
      <div className="sb-app">
        <div className="sb-step-container">
          <div className="sb-loading">Checking existing cart booking...</div>
        </div>
      </div>
    );
  }

  if (hasCartBookingGate) {
    return (
      <div className="sb-app">
        <div className="sb-step-container">
          <h2 className="sb-step__title">Existing Booking In Cart</h2>
          <p className="sb-empty">
            You already have a booking in your cart. Continue checkout or remove it before starting a new booking.
          </p>
          <div className="sb-step__actions">
            <button
              type="button"
              className="sb-btn sb-btn--danger"
              onClick={handleClearCartBooking}
              disabled={isClearingCart}
            >
              {isClearingCart ? "Removing..." : "Remove all cart"}
            </button>
            <button type="button" className="sb-btn sb-btn--primary" onClick={handleGoToCheckout}>
              Go to checkout
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="sb-app">
      <StepProgress currentStep={currentStep} hasPackageQuestionsStep={hasPackageQuestionsStep()} />

      <div className="sb-step-container">
        {currentStep === 1 && <Step1Selection />}
        {currentStep === 2 && <Step2Scheduling />}
        {currentStep === 3 && <Step3Addons />}
        {currentStep === 4 && <Step4PackageQuestions />}
        {currentStep === 5 && <Step4Terms />}
        {currentStep === 6 && <Step5Payment />}
        {currentStep === 7 && <Step6Confirmation />}
      </div>
    </div>
  );
}
