export function useCurrencyFormatter() {
    const formatCurrency = (price: number): string => {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
            minimumFractionDigits: 0,
        }).format(price);
    };

    return formatCurrency;
}
