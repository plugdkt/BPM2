import { Wallet, TrendingDown, PiggyBank, Inbox } from "lucide-react";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { getAllocationSummaries } from "@/lib/budget";
import { formatBaht } from "@/lib/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { BudgetBarChart } from "@/components/budget-bar-chart";
import { StatCard } from "@/components/stat-card";
import { DepartmentBudgetCard } from "@/components/department-budget-card";
import { EmptyState } from "@/components/empty-state";

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
    return (
      <EmptyState
        icon={Inbox}
        title="ยังไม่มีข้อมูลปีงบประมาณในระบบ"
        description="ให้ผู้ดูแลระบบไปที่หน้า “จัดการงบประมาณ” เพื่อตั้งปีงบประมาณและจัดสรรงบก่อน"
      />
    );
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

  const byDepartment = Object.entries(
    summaries.reduce<Record<string, typeof summaries>>((acc, s) => {
      (acc[s.departmentName] ??= []).push(s);
      return acc;
    }, {})
  );

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">ภาพรวมงบประมาณ</h1>
        <p className="text-sm text-muted-foreground">ปีงบประมาณ {fiscalYear.yearBE}</p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard
          label="งบที่ได้รับจัดสรร"
          value={formatBaht(totalAllocated)}
          icon={Wallet}
          tone="blue"
        />
        <StatCard label="ใช้ไปแล้ว" value={formatBaht(totalExpense)} icon={TrendingDown} tone="orange" />
        <StatCard label="คงเหลือ" value={formatBaht(totalBalance)} icon={PiggyBank} tone="green" />
      </div>

      {summaries.length === 0 ? (
        <Card>
          <CardContent>
            <EmptyState
              icon={Inbox}
              title="ยังไม่มีการจัดสรรงบประมาณ"
              description="เมื่อผู้ดูแลระบบจัดสรรงบให้สาขาวิชาแล้ว ข้อมูลจะแสดงที่นี่"
            />
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardHeader>
              <CardTitle>เปรียบเทียบตามหมวดงบประมาณ</CardTitle>
            </CardHeader>
            <CardContent>
              <BudgetBarChart data={byCategory} />
            </CardContent>
          </Card>

          <div>
            <h2 className="mb-3 text-lg font-semibold tracking-tight">แยกตามสาขาวิชา</h2>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {byDepartment.map(([departmentName, rows]) => (
                <DepartmentBudgetCard
                  key={departmentName}
                  departmentName={departmentName}
                  rows={rows}
                />
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
