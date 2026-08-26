import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { getAllocationSummaries } from "@/lib/budget";
import { formatBaht } from "@/lib/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { BudgetBarChart } from "@/components/budget-bar-chart";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

export default async function DashboardPage({
  searchParams,
}: {
  searchParams: Promise<{ fy?: string }>;
}) {
  const user = await requireUser();
  const { fy } = await searchParams;

  const fiscalYears = await prisma.fiscalYear.findMany({ orderBy: { yearBE: "desc" } });
  const fiscalYear = fy
    ? fiscalYears.find((f) => f.id === fy) ?? fiscalYears[0]
    : fiscalYears[0];

  if (!fiscalYear) {
    return <p className="text-muted-foreground">ยังไม่มีข้อมูลปีงบประมาณในระบบ</p>;
  }

  const departmentId = user.role === "DEPT_STAFF" ? user.departmentId! : undefined;
  const summaries = await getAllocationSummaries({ fiscalYearId: fiscalYear.id, departmentId });

  const totalAllocated = summaries.reduce((s, a) => s + a.allocated, 0);
  const totalExpense = summaries.reduce((s, a) => s + a.expense, 0);
  const totalBalance = summaries.reduce((s, a) => s + a.balance, 0);

  const byCategory = Object.values(
    summaries.reduce<Record<string, { name: string; จัดสรร: number; ใช้ไป: number; คงเหลือ: number }>>(
      (acc, s) => {
        acc[s.categoryName] ??= { name: s.categoryName, จัดสรร: 0, ใช้ไป: 0, คงเหลือ: 0 };
        acc[s.categoryName].จัดสรร += s.allocated;
        acc[s.categoryName].ใช้ไป += s.expense;
        acc[s.categoryName].คงเหลือ += s.balance;
        return acc;
      },
      {}
    )
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">
          ภาพรวมงบประมาณ ปีงบประมาณ {fiscalYear.yearBE}
        </h1>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">
              งบที่ได้รับจัดสรร
            </CardTitle>
          </CardHeader>
          <CardContent className="text-2xl font-semibold">
            {formatBaht(totalAllocated)}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">
              ใช้ไปแล้ว
            </CardTitle>
          </CardHeader>
          <CardContent className="text-2xl font-semibold">
            {formatBaht(totalExpense)}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium text-muted-foreground">
              คงเหลือ
            </CardTitle>
          </CardHeader>
          <CardContent className="text-2xl font-semibold">
            {formatBaht(totalBalance)}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>เปรียบเทียบตามหมวดงบประมาณ</CardTitle>
        </CardHeader>
        <CardContent>
          <BudgetBarChart data={byCategory} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>รายละเอียดตามสาขาวิชา / หมวดงบ</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>สาขาวิชา</TableHead>
                <TableHead>หมวดงบ</TableHead>
                <TableHead className="text-right">ได้รับจัดสรร</TableHead>
                <TableHead className="text-right">ใช้ไป</TableHead>
                <TableHead className="text-right">คงเหลือ</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {summaries.map((s) => (
                <TableRow key={s.allocationId}>
                  <TableCell>{s.departmentName}</TableCell>
                  <TableCell>{s.categoryName}</TableCell>
                  <TableCell className="text-right">{formatBaht(s.allocated)}</TableCell>
                  <TableCell className="text-right">{formatBaht(s.expense)}</TableCell>
                  <TableCell className="text-right font-medium">
                    {formatBaht(s.balance)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
