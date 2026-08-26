import { formatBaht } from "@/lib/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import type { AllocationSummary } from "@/lib/budget";

function UsageBar({ allocated, expense }: { allocated: number; expense: number }) {
  const pct = allocated > 0 ? Math.min(100, (expense / allocated) * 100) : 0;
  const over = allocated > 0 && expense > allocated;
  return (
    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
      <div
        className={cn("h-full rounded-full", over ? "bg-rose-500" : "bg-primary")}
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}

export function DepartmentBudgetCard({
  departmentName,
  rows,
}: {
  departmentName: string;
  rows: AllocationSummary[];
}) {
  const allocated = rows.reduce((s, r) => s + r.allocated, 0);
  const expense = rows.reduce((s, r) => s + r.expense, 0);
  const balance = rows.reduce((s, r) => s + r.balance, 0);

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-2 border-b [.border-b]:pb-3">
        <CardTitle>{departmentName}</CardTitle>
        <div className="text-right">
          <p className="text-xs text-muted-foreground">คงเหลือ</p>
          <p className="font-semibold tabular-nums">{formatBaht(balance)}</p>
        </div>
      </CardHeader>
      <CardContent className="space-y-4 pt-4">
        {rows.map((r) => (
          <div key={r.allocationId} className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-2 text-sm">
              <span className="font-medium">{r.categoryName}</span>
              <span className="shrink-0 tabular-nums text-muted-foreground">
                {formatBaht(r.expense)} / {formatBaht(r.allocated)}
              </span>
            </div>
            <UsageBar allocated={r.allocated} expense={r.expense} />
          </div>
        ))}
        <div className="flex items-center justify-between border-t pt-3 text-sm text-muted-foreground">
          <span>รวมได้รับจัดสรร</span>
          <span className="tabular-nums">{formatBaht(allocated)}</span>
        </div>
      </CardContent>
    </Card>
  );
}
