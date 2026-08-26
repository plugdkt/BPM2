"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { formatBaht } from "@/lib/format";

export function BudgetBarChart({
  data,
}: {
  data: { name: string; จัดสรร: number; ใช้ไป: number; คงเหลือ: number }[];
}) {
  return (
    <ResponsiveContainer width="100%" height={320}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 8, bottom: 8 }}>
        <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
        <XAxis dataKey="name" fontSize={12} tickLine={false} />
        <YAxis
          fontSize={12}
          tickLine={false}
          tickFormatter={(v) => new Intl.NumberFormat("th-TH", { notation: "compact" }).format(v)}
        />
        <Tooltip formatter={(value) => formatBaht(Number(value))} />
        <Legend />
        <Bar dataKey="จัดสรร" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
        <Bar dataKey="ใช้ไป" fill="var(--chart-2)" radius={[4, 4, 0, 0]} />
        <Bar dataKey="คงเหลือ" fill="var(--chart-3)" radius={[4, 4, 0, 0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}
