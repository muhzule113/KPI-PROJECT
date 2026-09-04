export function formatNumber(value, maximumFractionDigits = 1) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'Belum ada';
    }

    return new Intl.NumberFormat('id-ID', {
        maximumFractionDigits,
    }).format(Number(value));
}

export function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'Belum ada';
    }

    return `${formatNumber(value)}%`;
}

export function formatShortDate(value) {
    if (!value) {
        return 'Tanggal tidak tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}
