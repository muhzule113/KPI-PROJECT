import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import DataState from '@/components/feedback/DataState';
import { formatNumber } from '@/utils/formatters';

function shortLabel(label) {
    if (!label) {
        return '';
    }

    return label.length > 10 ? `${label.slice(0, 9)}...` : label;
}

export default function TrendChart({ data = [] }) {
    const hasData = data.some((item) => item.average !== null && item.average !== undefined);

    if (!hasData) {
        return (
            <DataState
                compact
                title="Trend belum tersedia"
                description="Grafik akan muncul setelah skor final tersimpan pada periode yang tersedia."
            />
        );
    }

    return (
        <div className="h-64 w-full" role="img" aria-label="Grafik rata-rata pencapaian KPI per periode">
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 10, right: 6, left: -18, bottom: 0 }}>
                    <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                    <XAxis
                        dataKey="label"
                        tickFormatter={shortLabel}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                        axisLine={false}
                        tickLine={false}
                        dy={8}
                    />
                    <YAxis
                        domain={[0, 100]}
                        tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                        axisLine={false}
                        tickLine={false}
                        tickFormatter={(value) => `${value}%`}
                        width={42}
                    />
                    <Tooltip
                        cursor={{ stroke: 'var(--primary)', strokeOpacity: 0.22 }}
                        contentStyle={{
                            backgroundColor: 'var(--popover)',
                            border: '1px solid var(--border)',
                            borderRadius: '12px',
                            boxShadow: '0 10px 25px rgb(15 23 42 / 0.12)',
                        }}
                        labelStyle={{ color: 'var(--popover-foreground)', fontWeight: 600, marginBottom: 4 }}
                        itemStyle={{ color: 'var(--primary)' }}
                        formatter={(value) => [`${formatNumber(value)}%`, 'Rata-rata']}
                    />
                    <Area
                        type="monotone"
                        dataKey="average"
                        stroke="var(--primary)"
                        strokeWidth={2.5}
                        fill="var(--primary)"
                        fillOpacity={0.1}
                        connectNulls={false}
                        activeDot={{ r: 5, fill: 'var(--primary)', stroke: 'var(--card)', strokeWidth: 2 }}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}
