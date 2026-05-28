import { renderExtraPriceDisplay, isMinusDisabled, isPlusDisabled } from "./Step3Addons.utils";
import type { ExtraCardProps } from "./Step3Addons.types";

// Simple SVG icons
const MinusIcon = () => (
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
    <path d="M3 8h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

const PlusIcon = () => (
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
    <path d="M8 3v10M3 8h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
  </svg>
);

/**
 * ExtraCard - Individual extra item with quantity controls
 */
export function ExtraCard({ extra, merged, packageTitle, isSelected, onIncrement, onDecrement, onRemove }: ExtraCardProps) {
  const minusDisabled = isMinusDisabled(merged);
  const plusDisabled = isPlusDisabled(extra);

  return (
    <div className={`sb-extra-card ${isSelected ? "sb-extra-card--selected" : ""} ${!extra.is_available ? "sb-extra-card--unavailable" : ""}`}>
      <div className="sb-extra-card__info">
        {extra.thumbnail && (
          <img src={extra.thumbnail} alt={extra.title} className="sb-extra-card__img" />
        )}
        <div>
          <strong className="sb-extra-card__name">{extra.title}</strong>
          <p className="sb-extra-card__desc">{extra.description}</p>
          
          {/* Price display */}
          {renderExtraPriceDisplay(extra, merged, packageTitle)}
          
          {/* Badges */}
          {!extra.is_available && extra.unavailable_reason && (
            <span className={`sb-badge sb-badge--sold-out ${extra.unavailable_reason === "space_override" ? "sb-badge--closed" : ""}`}>
              {extra.unavailable_reason === "space_override" ? "Closed this time" : "Sold Out"}
            </span>
          )}
          {extra.is_available && extra.available_qty < 3 && (
            <span className="sb-badge sb-badge--low">Only {extra.available_qty} left</span>
          )}
        </div>
      </div>

      {/* Quantity controls */}
      {isSelected && merged ? (
        <div className="sb-extra-card__controls">
          <button
            className="sb-btn sb-btn--qty sb-btn--qty-minus"
            onClick={onDecrement}
            disabled={minusDisabled}
            aria-label="Decrease quantity"
          >
            <MinusIcon />
          </button>
          <span className="sb-extra-card__qty">{merged.total_qty}</span>
          <button
            className="sb-btn sb-btn--qty sb-btn--qty-plus"
            onClick={onIncrement}
            disabled={plusDisabled}
            aria-label="Increase quantity"
          >
            <PlusIcon />
          </button>
        </div>
      ) : (
        <button
          className="sb-btn sb-btn--secondary"
          disabled={!extra.is_available}
          onClick={onRemove}
        >
          Add
        </button>
      )}
    </div>
  );
}
