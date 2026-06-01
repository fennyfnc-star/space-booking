import { useEffect, useRef, useState } from "react";
import DOMPurify from "dompurify";
import { useBookingStore } from "@/store/bookingStore";

const BOOKING_POLICY_ALLOWED_TAGS = [
  "p",
  "br",
  "strong",
  "b",
  "em",
  "i",
  "u",
  "strike",
  "h1",
  "h2",
  "h3",
  "h4",
  "h5",
  "h6",
  "ul",
  "ol",
  "li",
  "a",
  "div",
  "span",
  "blockquote",
  "pre",
  "code",
  "hr",
  "table",
  "thead",
  "tbody",
  "tfoot",
  "tr",
  "th",
  "td",
  "caption",
  "figure",
  "figcaption",
  "img",
  "small",
  "sup",
  "sub",
  "mark",
] as const;

const BOOKING_POLICY_ALLOWED_ATTR = [
  "href",
  "target",
  "title",
  "rel",
  "class",
  "src",
  "alt",
  "width",
  "height",
  "loading",
  "decoding",
  "colspan",
  "rowspan",
  "scope",
  "align",
] as const;

function sanitizeBookingPolicy(policy: string): string {
  return DOMPurify.sanitize(policy, {
    ALLOWED_TAGS: [...BOOKING_POLICY_ALLOWED_TAGS],
    ALLOWED_ATTR: [...BOOKING_POLICY_ALLOWED_ATTR],
  });
}

export function Step4Terms() {
  const { nextStep, prevStep } = useBookingStore();
  const policyRef = useRef<HTMLDivElement | null>(null);
  const [bookingPolicy, setBookingPolicy] = useState(() =>
    sanitizeBookingPolicy(window.sbConfig?.bookingPolicy || ""),
  );
  const [agreed, setAgreed] = useState(false);
  const [canAgree, setCanAgree] = useState(false);
  const [error, setError] = useState("");
  const [showConfirm, setShowConfirm] = useState(false);

  useEffect(() => {
    setBookingPolicy(
      sanitizeBookingPolicy(window.sbConfig?.bookingPolicy || ""),
    );
  }, []);

  useEffect(() => {
    const el = policyRef.current;
    if (!el) {
      return;
    }

    setCanAgree(el.scrollHeight <= el.clientHeight + 1);
  }, [bookingPolicy]);

  useEffect(() => {
    if (!canAgree) {
      setAgreed(false);
    }
  }, [canAgree]);

  const handleNext = () => {
    if (!agreed) {
      setError("You must agree to the booking policy to continue.");
      return;
    }
    setError("");
    setShowConfirm(true);
  };

  const handleConfirmYes = () => {
    setShowConfirm(false);
    nextStep();
  };

  const handleConfirmNo = () => {
    setShowConfirm(false);
  };

  if (!bookingPolicy) {
    return (
      <div className="sb-step sb-step-4">
        <h2 className="sb-step__title">Terms & Agreement</h2>
        <div className="sb-error">
          Booking policy not configured. Please contact administrator.
        </div>
        <div className="sb-step__actions">
          <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
            Back
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="sb-step sb-step-4">
      <h2 className="sb-step__title">Terms & Agreement</h2>

      <div className="sb-policy-container">
        <div className="sb-policy-shell">
          <div
            ref={policyRef}
            className="sb-policy-text"
            dangerouslySetInnerHTML={{ __html: bookingPolicy }}
            onScroll={(e) => {
              const target = e.target as HTMLDivElement;
              const atBottom =
                target.scrollTop + target.clientHeight >=
                target.scrollHeight - 1;
              setCanAgree(atBottom);
            }}
          />
        </div>

        <div className="sb-note sb-policy-note">
          Scroll to the bottom of the terms above to enable the checkbox.
        </div>

        <label className="sb-checkbox-label">
          <input
            type="checkbox"
            checked={agreed}
            onChange={(e) => setAgreed(e.target.checked)}
            disabled={!canAgree}
          />
          <span className="sb-checkbox-mark"></span>
          <span className="sb-checkbox-copy">
            I have read and agree to the booking policy above
          </span>
        </label>

        {error && (
          <div className="sb-error sb-error--mt">
            {error}
          </div>
        )}
      </div>

      <div className="sb-step__actions">
        <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
          Back
        </button>
        <button
          className="sb-btn sb-btn--primary"
          onClick={handleNext}
          disabled={!agreed}
        >
          Agree & Continue to Payment
        </button>
      </div>

      {showConfirm && (
        <>
          <div
            style={{
              position: "fixed",
              top: 0,
              left: 0,
              right: 0,
              bottom: 0,
              backgroundColor: "rgba(0, 0, 0, 0.5)",
              zIndex: 9999,
              display: "flex",
              alignItems: "center",
              justifyContent: "center",
              animation: "fadeIn 0.2s ease-out",
            }}
            onClick={handleConfirmNo}
          />

          <div
            style={{
              position: "fixed",
              top: "50%",
              left: "50%",
              transform: "translate(-50%, -50%)",
              background: "white",
              padding: "32px",
              borderRadius: "12px",
              boxShadow: "0 20px 40px rgba(0,0,0,0.3)",
              maxWidth: "500px",
              width: "90%",
              zIndex: 10000,
              animation: "slideIn 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94)",
            }}
          >
            <h3 style={{ margin: "0 0 16px 0", color: "#333" }}>
              Final Confirmation
            </h3>
            <p
              style={{
                margin: "0 0 24px 0",
                lineHeight: "1.6",
                color: "#555",
                fontSize: "16px",
              }}
            >
              By proceeding, you acknowledge that you have read and fully
              understood the terms of this policy because you will be held
              liable.
            </p>

            <div
              style={{
                display: "flex",
                gap: "12px",
                justifyContent: "flex-end",
              }}
            >
              <button
                className="sb-btn sb-btn--ghost"
                onClick={handleConfirmNo}
                style={{ padding: "12px 24px" }}
              >
                No, let me read again
              </button>
              <button
                className="sb-btn sb-btn--primary"
                onClick={handleConfirmYes}
                style={{ padding: "12px 24px" }}
              >
                Yes, continue
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
