import { useMemo, useState } from "react";
import { useBookingStore } from "@/store/bookingStore";
import type { PackageThemeMetaField, SelectionItem } from "@/types";

type SelectedPackageItem = Extract<SelectionItem, { type: "package" }>;

type PackageQuestionEntry = {
  packageId: number;
  packageTitle: string;
  field: PackageThemeMetaField;
};

const answerKeyFor = (packageId: number, fieldKey: string) =>
  `pkg_${packageId}__${fieldKey}`;

const hasOthersSelected = (value: string | number | string[] | undefined): boolean =>
  Array.isArray(value) ? value.includes("Others") : value === "Others";

export function Step4PackageQuestions() {
  const {
    selectedItems,
    packageQuestionAnswers,
    setPackageQuestionAnswer,
    nextStep,
    prevStep,
  } = useBookingStore();

  const [errors, setErrors] = useState<Record<string, string>>({});

  const entries = useMemo<PackageQuestionEntry[]>(() => {
    return selectedItems
      .filter((item): item is SelectedPackageItem => item.type === "package")
      .flatMap((pkg) => {
        const fields = Array.isArray(pkg.theme_meta_fields) ? pkg.theme_meta_fields : [];
        return fields.map((field) => ({
          packageId: Number(pkg.id),
          packageTitle: pkg.title,
          field,
        }));
      });
  }, [selectedItems]);

  const setValue = (
    key: string,
    value: string | number | string[],
    othersText?: string,
  ) => {
    const othersSelected = hasOthersSelected(value);
    setPackageQuestionAnswer(key, value, othersSelected ? othersText : "");
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      if (!othersSelected || othersText !== undefined) {
        delete next[`${key}__others`];
      }
      return next;
    });
  };

  const validate = (): boolean => {
    const validationErrors: Record<string, string> = {};
    for (const entry of entries) {
      const key = answerKeyFor(entry.packageId, entry.field.key);
      const existing = packageQuestionAnswers[key];
      const value = existing?.value;
      const isEmpty =
        value === undefined ||
        value === null ||
        value === "" ||
        (Array.isArray(value) && value.length === 0);
      if (entry.field.required && isEmpty) {
        validationErrors[key] = "This field is required.";
      }

      const supportsOthers =
        !!entry.field.allow_others &&
        ["radio", "checkbox", "select"].includes(entry.field.type);
      const othersSelected =
        supportsOthers &&
        ((Array.isArray(value) && value.includes("Others")) || value === "Others");
      if (othersSelected && !String(existing?.others_text || "").trim()) {
        validationErrors[`${key}__others`] =
          "Please describe your \"Others\" answer.";
      }
    }
    setErrors(validationErrors);
    return Object.keys(validationErrors).length === 0;
  };

  const handleContinue = () => {
    if (!validate()) return;
    nextStep();
  };

  if (entries.length === 0) return null;

  return (
    <div className="sb-step sb-step-4">
      <h2 className="sb-step__title">Package Questions</h2>
      <p className="sb-step__subtitle">
        Please answer these package-specific questions before continuing.
      </p>

      <div className="sb-summary-grid">
        {entries.map((entry) => {
          const key = answerKeyFor(entry.packageId, entry.field.key);
          const answer = packageQuestionAnswers[key];
          const value = answer?.value;
          const type = entry.field.type;
          const options = Array.isArray(entry.field.options) ? entry.field.options : [];
          const optionPrices =
            entry.field.option_prices && typeof entry.field.option_prices === "object"
              ? entry.field.option_prices
              : {};
          const hasPricedOptions =
            !!entry.field.priced_options &&
            ["radio", "checkbox", "select"].includes(type);
          const renderOptionLabel = (opt: string) => {
            if (!hasPricedOptions) return opt;
            const amount = Number(optionPrices[opt] ?? 0);
            if (amount <= 0) return opt;
            return `${opt} (+${window.sbConfig.symbol}${amount.toFixed(2)})`;
          };
          const othersEnabled =
            !!entry.field.allow_others &&
            ["radio", "checkbox", "select"].includes(type);
          const othersSelected =
            othersEnabled &&
            ((Array.isArray(value) && value.includes("Others")) || value === "Others");

          return (
            <div
              key={`${entry.packageId}-${entry.field.key}`}
              className="sb-summary-row"
              style={{ gridColumn: "1 / -1", display: "block" }}
            >
              <label style={{ display: "block", marginBottom: 6 }}>
                {entry.field.label}{" "}
                {entry.field.required ? <span style={{ color: "#d63638" }}>*</span> : null}
                <span style={{ marginLeft: 8, color: "#666", fontSize: 12 }}>
                  ({entry.packageTitle})
                </span>
              </label>

              {type === "text" && (
                <input
                  className="sb-input"
                  type="text"
                  value={String(value || "")}
                  onChange={(e) => setValue(key, e.target.value)}
                />
              )}
              {type === "textarea" && (
                <textarea
                  className="sb-input"
                  value={String(value || "")}
                  onChange={(e) => setValue(key, e.target.value)}
                  style={{ minHeight: 90 }}
                />
              )}
              {type === "number" && (
                <input
                  className="sb-input"
                  type="number"
                  value={String(value || "")}
                  onChange={(e) => setValue(key, e.target.value)}
                />
              )}
              {type === "select" && (
                <select
                  className="sb-input"
                  value={String(value || "")}
                  onChange={(e) => setValue(key, e.target.value)}
                >
                  <option value="">Select an option</option>
                  {options.map((opt) => (
                    <option key={opt} value={opt}>
                      {renderOptionLabel(opt)}
                    </option>
                  ))}
                  {othersEnabled && (
                    <option value="Others">
                      {hasPricedOptions ? renderOptionLabel("Others") : "Others"}
                    </option>
                  )}
                </select>
              )}
              {type === "radio" && (
                <div>
                  {options.map((opt) => (
                    <label key={opt} style={{ display: "block", marginBottom: 6 }}>
                      <input
                        type="radio"
                        name={key}
                        checked={value === opt}
                        onChange={() =>
                          setValue(key, opt, answer?.others_text || "")
                        }
                      />{" "}
                      {renderOptionLabel(opt)}
                    </label>
                  ))}
                  {othersEnabled && (
                    <label style={{ display: "block", marginBottom: 6 }}>
                      <input
                        type="radio"
                        name={key}
                        checked={value === "Others"}
                        onChange={() => setValue(key, "Others")}
                      />{" "}
                      {hasPricedOptions ? renderOptionLabel("Others") : "Others"}
                    </label>
                  )}
                </div>
              )}
              {type === "checkbox" && (
                <div>
                  {options.map((opt) => {
                    const arr = Array.isArray(value) ? value : [];
                    const checked = arr.includes(opt);
                    return (
                      <label key={opt} style={{ display: "block", marginBottom: 6 }}>
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={(e) => {
                            const current = Array.isArray(value) ? value : [];
                            const next = e.target.checked
                              ? [...current, opt]
                              : current.filter((v) => v !== opt);
                            setValue(key, next, answer?.others_text || "");
                          }}
                        />{" "}
                        {renderOptionLabel(opt)}
                      </label>
                    );
                  })}
                  {othersEnabled && (
                    <label style={{ display: "block", marginBottom: 6 }}>
                      <input
                        type="checkbox"
                        checked={Array.isArray(value) ? value.includes("Others") : false}
                        onChange={(e) => {
                          const current = Array.isArray(value) ? value : [];
                          const next = e.target.checked
                            ? [...current, "Others"]
                            : current.filter((v) => v !== "Others");
                          setValue(key, next, answer?.others_text || "");
                        }}
                      />{" "}
                      {hasPricedOptions ? renderOptionLabel("Others") : "Others"}
                    </label>
                  )}
                </div>
              )}

              {othersSelected && (
                <div style={{ marginTop: 8 }}>
                  <label style={{ display: "block", marginBottom: 4 }}>
                    Please specify
                  </label>
                  <textarea
                    className="sb-input"
                    value={String(answer?.others_text || "")}
                    onChange={(e) => setValue(key, answer?.value || "", e.target.value)}
                    style={{ minHeight: 90 }}
                  />
                  {errors[`${key}__others`] && (
                    <span className="sb-error">{errors[`${key}__others`]}</span>
                  )}
                </div>
              )}
              {errors[key] && <span className="sb-error">{errors[key]}</span>}
            </div>
          );
        })}
      </div>

      <div className="sb-step__actions">
        <button className="sb-btn sb-btn--ghost" onClick={prevStep}>
          Back
        </button>
        <button className="sb-btn sb-btn--primary" onClick={handleContinue}>
          Continue
        </button>
      </div>
    </div>
  );
}
