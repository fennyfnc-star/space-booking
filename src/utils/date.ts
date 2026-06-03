export function formatBookingDate(rawDate: string): string {
  const value = rawDate.trim();
  if (!value) return "";

  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return rawDate;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));

  if (Number.isNaN(date.getTime())) {
    return rawDate;
  }

  return new Intl.DateTimeFormat("en-US", {
    month: "long",
    day: "numeric",
    year: "numeric",
    timeZone: "UTC",
  }).format(date);
}
