// Shapes sent by GenerationController. Money always arrives as a ready-made
// string ("$0.4674"); the browser never does arithmetic on it.

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    uuid: string;
    status: GenerationStatus;
    status_label: string;
    waiting_for_slot: boolean;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_image_count: number;
    estimated_min: string;
    estimated_max: string;
    estimated_max_exact: string;
    charged: string | null;
    charged_exact: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type FormOptions = {
    sizes: string[];
    qualities: string[];
};

export type FormLimits = {
    prompt: number;
    reference_images: number;
};

export type Estimate = {
    min: string;
    max: string;
    min_exact: string;
    max_exact: string;
};
