import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatBaht } from "@/lib/format";
import { fiscalQuarterOf, THAI_MONTHS } from "@/lib/fiscal-year";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ReportFilters } from "@/components/report-filters";

const QUARTER_LABEL: Record<number, string> = {
  1: "ไตรมาส 1 (ต.ค.-ธ.ค.)",
  2: "ไตรมาส 2 (ม.ค.-มี.ค.)",
  3: "ไตรมาส 3 (เม.ย.-มิ.ย.)",
  4: "ไตรมาส 4 (ก.ค.-ก.ย.)",
};

export default async function ReportsPage({
  searchParams,
}: {
  searchParams: Promise<{ fy?: string; dept?: string; view?: string }>;
}) {
  const user = await requireUser();
  const { fy, dept, view } = await searchParams;
  const viewMode = view === "quarter" ? "quarter" : "month";

  const [fiscalYears, departments] = await Promise.all([
    prisma.fiscalYear.findMany({ orderBy: { yearBE: "desc" } }),
    prisma.department.findMany({ orderBy: { name: "asc" } }),
  ]);

  const fiscalYear = fy ? fiscalYears.find((f) => f.id === fy) ?? fiscalYears[0] : fiscalYears[0];

  if (!fiscalYear) {
    return <p className="text-muted-foreground">ยังไม่มีข้อมูลปีงบประมาณในระบบ</p>;
  }

  const departmentId =
    user.role === "DEPT_STAFF" ? user.departmentId! : dept && dept !== "all" ? dept : undefined;

  const transactions = await prisma.transaction.findMany({
    where: {
      allocation: {
        fiscalYearId: fiscalYear.id,
        ...(departmentId ? { departmentId } : {}),
      },
    },
    include: { allocation: { include: { category: true, department: true } } },
    orderBy: { date: "asc" },
  });

  type Row = { period: string; expense: number; income: number; net: number };
  const grouped = new Map<string, Row>();

  for (const t of transactions) {
    const date = new Date(t.date);
    const period =
      viewMode === "month"
        ? THAI_MONTHS[date.getUTCMonth()]
        : QUARTER_LABEL[fiscalQuarterOf(date)];

    const row = grouped.get(period) ?? { period, expense: 0, income: 0, net: 0 };
    const amount = Number(t.amount);
    if (t.type === "EXPENSE") row.expense += amount;
    else row.income += amount;
    row.net = row.income - row.expense;
    grouped.set(period, row);
  }

  const rows = Array.from(grouped.values());
  const totalExpense = rows.reduce((s, r) => s + r.expense, 0);
  const totalIncome = rows.reduce((s, r) => s + r.income, 0);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">รายงานงบประมาณ</h1>

      <Card>
        <CardHeader>
          <CardTitle>ตัวกรอง</CardTitle>
        </CardHeader>
        <CardContent>
          <ReportFilters
            fiscalYears={fiscalYears}
            departments={departments}
            canPickDepartment={user.role !== "DEPT_STAFF"}
            currentFy={fiscalYear.id}
            currentDept={dept ?? "all"}
            currentView={viewMode}
          />
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">
              รายจ่ายรวม
            </CardTitle>
          </CardHeader>
          <CardContent className="text-2xl font-semibold">{formatBaht(totalExpense)}</CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">
              รายรับรวม
            </CardTitle>
          </CardHeader>
          <CardContent className="text-2xl font-semibold">{formatBaht(totalIncome)}</CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>
            สรุป{viewMode === "month" ? "รายเดือน" : "รายไตรมาส"} · ปีงบประมาณ {fiscalYear.yearBE}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{viewMode === "month" ? "เดือน" : "ไตรมาส"}</TableHead>
                <TableHead className="text-right">รายจ่าย</TableHead>
                <TableHead className="text-right">รายรับ</TableHead>
                <TableHead className="text-right">สุทธิ</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={4} className="text-center text-muted-foreground">
                    ไม่มีข้อมูลในช่วงที่เลือก
                  </TableCell>
                </TableRow>
              )}
              {rows.map((r) => (
                <TableRow key={r.period}>
                  <TableCell>{r.period}</TableCell>
                  <TableCell className="text-right">{formatBaht(r.expense)}</TableCell>
                  <TableCell className="text-right">{formatBaht(r.income)}</TableCell>
                  <TableCell className="text-right font-medium">{formatBaht(r.net)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
