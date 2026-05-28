import type { BookingStep } from "@/types";

const STEPS: { label: string }[] = [
  { label: "Select" },
  { label: "Schedule" },
  { label: "Add-ons" },
  { label: "Terms" },
  { label: "Payment" },
  { label: "Confirm" },
];

interface Props {
  currentStep: BookingStep;
}

export function StepProgress({ currentStep }: Props) {
  return (
    <nav className="sb-progress" aria-label="Booking steps">
      <ol className="sb-progress__list">
        {STEPS.map((step, index) => {
          const num = (index + 1) as BookingStep;
          const status =
            num < currentStep
              ? "completed"
              : num === currentStep
                ? "active"
                : "upcoming";

          return (
            <li
              key={num}
              className={`sb-progress__item sb-progress__item--${status}`}
              aria-current={status === "active" ? "step" : undefined}
            >
              <span className="sb-progress__dot" aria-hidden="true">
                {status === "completed" ? "✓" : num}
              </span>
              <span className="sb-progress__label">{step.label}</span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
