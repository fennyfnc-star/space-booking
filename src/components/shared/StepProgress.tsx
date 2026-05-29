import type { BookingStep } from "@/types";

interface Props {
  currentStep: BookingStep;
  hasPackageQuestionsStep: boolean;
}

export function StepProgress({ currentStep, hasPackageQuestionsStep }: Props) {
  const steps: Array<{ step: BookingStep; label: string }> = hasPackageQuestionsStep
    ? [
        { step: 1, label: "Select" },
        { step: 2, label: "Schedule" },
        { step: 3, label: "Add-ons" },
        { step: 4, label: "Package Questions" },
        { step: 5, label: "Terms" },
        { step: 6, label: "Payment" },
        { step: 7, label: "Confirm" },
      ]
    : [
        { step: 1, label: "Select" },
        { step: 2, label: "Schedule" },
        { step: 3, label: "Add-ons" },
        { step: 5, label: "Terms" },
        { step: 6, label: "Payment" },
        { step: 7, label: "Confirm" },
      ];

  const activeIndex = steps.findIndex((s) => s.step === currentStep);

  return (
    <nav className="sb-progress" aria-label="Booking steps">
      <ol className="sb-progress__list">
        {steps.map((step, index) => {
          const status =
            index < activeIndex
              ? "completed"
              : index === activeIndex
                ? "active"
                : "upcoming";

          return (
            <li
              key={step.step}
              className={`sb-progress__item sb-progress__item--${status}`}
              aria-current={status === "active" ? "step" : undefined}
            >
              <span className="sb-progress__dot" aria-hidden="true">
                {status === "completed" ? "✓" : index + 1}
              </span>
              <span className="sb-progress__label">{step.label}</span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

