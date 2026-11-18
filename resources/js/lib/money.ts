import type { Nanodollars } from '@/types';

/**
 * The exact amount, e.g. 291_800_000 → "$0.2918". No rounding, so it always matches what was recorded.
 */
export function usd(amount: Nanodollars): string {
    const digits = String(Math.abs(amount)).padStart(10, '0');
    const fraction = digits.slice(-9).replace(/0+$/, '').padEnd(2, '0');

    return `${amount < 0 ? '-' : ''}$${digits.slice(0, -9)}.${fraction}`;
}

const approximate = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 4,
});

/**
 * A rounded amount for estimates, e.g. 412_562_500 → "$0.4126".
 */
export function usdApprox(amount: Nanodollars): string {
    return approximate.format(amount / 1e9);
}
