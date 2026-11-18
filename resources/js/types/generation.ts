/** Money is integer nanodollars (1e-9 USD) throughout. */
export type Nanodollars = number;

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_cost: Nanodollars;
    max_cost: Nanodollars;
    cost: Nanodollars | null;
    error_message: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    created_at: string;
};

export type Estimate = {
    min: Nanodollars;
    expected: Nanodollars;
    max: Nanodollars;
};

export type CreditBalance = {
    balance: Nanodollars;
    held: Nanodollars;
    available: Nanodollars;
};

export type SpendTotals = {
    images: number;
    charged: Nanodollars;
    provider_cost: Nanodollars;
    profit: Nanodollars;
};

export type SpendDay = SpendTotals & {
    date: string;
    /** What Pixelforge's GET /v1/usage reports for the day; null if it couldn't be asked. */
    pixelforge_cost: Nanodollars | null;
};

export type SpendByUser = SpendTotals & {
    date: string;
    /** Null once the account has been deleted. */
    user: { name: string; email: string } | null;
};

export type Paginated<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
