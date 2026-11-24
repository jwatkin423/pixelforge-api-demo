/**
 * All money arrives from the server as integer nanodollars (1 USD = 1e9).
 * These helpers only format; no arithmetic that matters happens in the browser.
 */
const NANOS_PER_DOLLAR = 1_000_000_000;

/**
 * Friendly USD: at least 2 and up to 4 decimals ($5.00, $0.0577), more for tiny amounts.
 */
export function formatUsd(nanos: number): string {
    const dollars = nanos / NANOS_PER_DOLLAR;
    const absolute = Math.abs(dollars);
    const maximumFractionDigits = absolute > 0 && absolute < 0.0001 ? 6 : 4;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits,
    }).format(dollars);
}

/**
 * Exact nine-decimal amount, as Pixelforge reports it ("$0.046125000").
 */
export function exactUsd(nanos: number): string {
    const sign = nanos < 0 ? '-' : '';
    const digits = Math.abs(Math.trunc(nanos)).toString().padStart(10, '0');

    return `${sign}$${digits.slice(0, -9)}.${digits.slice(-9)}`;
}
