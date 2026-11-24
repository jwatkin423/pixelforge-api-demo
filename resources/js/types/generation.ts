export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Option = {
    value: string;
    label: string;
};

export type Generation = {
    id: string;
    status: GenerationStatus;
    status_detail: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min_charge_nanos: number;
    estimated_max_charge_nanos: number;
    charged_nanos: number | null;
    usage: Record<string, number> | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
};

export type CostEstimate = {
    min_charge_nanos: number;
    max_charge_nanos: number;
    text_tokens_min: number;
    text_tokens_max: number;
    image_tokens: number;
    output_tokens: number;
    reasoning_tokens_min: number;
    reasoning_tokens_max: number;
    balance_nanos: number;
    markup_percent: number;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
