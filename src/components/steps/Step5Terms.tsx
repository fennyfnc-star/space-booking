import React, { useState, useEffect } from "react";
import DOMPurify from "dompurify";
import { useBookingStore } from "@/store/bookingStore";
import type { BookingStep } from "@/types";

export function Step5Terms() {
  const { nextStep, prevStep } = useBookingStore();
  const [bookingPolicy, setBookingPolicy] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [canAgree, setCanAgree] = useState(false);
  const [error, setError] = useState("");
  const [showConfirm, setShowConfirm] = useState(false);

  useEffect(() => {
    // Load policy from global config
    if (window.sbConfig?.bookingPolicy && !bookingPolicy) {
      const purified = DOMPurify.sanitize(window.sbConfig.bookingPolicy, {
        ALLOWED_TAGS: [
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
        ],
        ALLOWED_ATTR: ["href", "target", "title", "rel"],
      });
      setBookingPolicy(purified);
    }
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
      <div className="sb-step sb-step-5">
        <h2 className="sb-step__title">Terms & Agreement</h2>
        <div className="sb-error">
          Booking policy not configured. Please contact administrator.
        </div>
        <div className="sb-step__actions">
          <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
            ← Back
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="sb-step sb-step-5">
      <h2 className="sb-step__title">Terms & Agreement</h2>

      <div className="sb-policy-container">
        <div
          className="sb-policy-text"
          dangerouslySetInnerHTML={{ __html: bookingPolicy }}
          style={{
            maxHeight: "400px",
            overflow: "auto",
            border: "1px solid #ddd",
            padding: "16px",
            borderRadius: "4px",
            background: "#f9f9f9",
            marginBottom: "16px",
            fontFamily: "system-ui, -apple-system, sans-serif",
            lineHeight: "1.6",
            fontSize: "15px",
          }}
          onScroll={(e) => {
            const target = e.target as HTMLDivElement;
            const atBottom =
              target.scrollTop + target.clientHeight >= target.scrollHeight - 1;
            setCanAgree(atBottom);
          }}
        />
        <style>{`
          .sb-policy-text p {
            margin-bottom: 1em;
            margin-top: 0;
          }
          .sb-policy-text p:last-child {
            margin-bottom: 0;
          }
          .sb-policy-text h1,
          .sb-policy-text h2,
          .sb-policy-text h3 {
            margin-top: 2em;
            margin-bottom: 1em;
            font-weight: bold;
            line-height: 1.3;
          }
          .sb-policy-text h1 { 
            font-size: 2em; 
          }
          .sb-policy-text h2 { 
            font-size: 1.5em; 
          }
          .sb-policy-text h3 { 
            font-size: 1.25em; 
          }
          .sb-policy-text ul,
          .sb-policy-text ol {
            padding-left: 2em;
            margin-bottom: 1em;
          }
          .sb-policy-text li {
            margin-bottom: 0.5em;
          }
          .sb-policy-text strong,
          .sb-policy-text b {
            font-weight: 600;
          }
          .sb-policy-text em,
          .sb-policy-text i {
            font-style: italic;
          }
          .sb-policy-text a {
            color: #0073aa;
            text-decoration: underline;
          }
          .sb-policy-text a:hover {
            color: #005a87;
          }
        `}</style>

        <div
          style={{
            marginBottom: "12px",
            fontSize: 14,
            color: "#666",
            fontStyle: "italic",
          }}
        >
          📜 Scroll to the bottom of the terms above to enable the checkbox
        </div>

        <label className="sb-checkbox-label">
          <input
            type="checkbox"
            checked={agreed}
            onChange={(e) => setAgreed(e.target.checked)}
            disabled={!canAgree}
          />
          <span className="sb-checkbox-mark"></span>
          <span style={{ fontSize: 14 }}>
            I have read and agree to the booking policy above
          </span>
        </label>

        {error && (
          <div className="sb-error" style={{ marginTop: "8px" }}>
            {error}
          </div>
        )}
      </div>

      <div className="sb-step__actions">
        <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
          ← Back
        </button>
        <button
          className="sb-btn sb-btn--primary"
          onClick={handleNext}
          disabled={!agreed}
        >
          Agree & Continue to Payment →
        </button>
      </div>

      {showConfirm && (
        <>
          {/* Backdrop */}
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

          {/* Modal */}
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
              ⚠️ Final Confirmation
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
