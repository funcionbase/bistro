export interface BusinessHour {
    id: string;
    company_nit: string;
    day_of_week: number; // 0 = Sunday, 6 = Saturday
    open_time: string | null; // HH:MM:SS
    close_time: string | null;
    is_enabled: boolean;
    created_at: string;
    updated_at: string;
}

export interface BusinessHourException {
    id: string;
    company_nit: string;
    exception_date: string; // YYYY-MM-DD
    reason: string;
    is_open: boolean;
    open_time: string | null;
    close_time: string | null;
    created_at: string;
    updated_at: string;
}

export interface BusinessHourFormData {
    day_of_week: number;
    is_enabled: boolean;
    open_time: string; // HH:MM
    close_time: string;
}

export interface BusinessHourExceptionFormData {
    exception_date: string;
    reason: string;
    is_open: boolean;
    open_time: string;
    close_time: string;
}

export interface RestaurantStatus {
    company_nit: string;
    is_open: boolean;
    reason: 'within_hours' | 'out_of_hours' | 'closed_by_exception' | 'open_by_exception' | 'not_in_service_window' | 'no_schedule_defined';
    exception_active: boolean;
    menu_available: boolean;
    menu_visibility_reason: 'visible' | 'restaurant_closed' | 'exception_closed' | 'not_in_service_window';
    current_time: string;
    next_opening: { day: string; time: string } | null;
}
