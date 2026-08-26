import { prisma } from "@/lib/prisma";
import { requireAdmin } from "@/lib/session";
import { formatBaht, formatDateThai } from "@/lib/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { FiscalYearForm } from "@/components/admin/fiscal-year-form";
import { CategoryForm } from "@/components/admin/category-form";
import { DepartmentForm } from "@/components/admin/department-form";
import { AllocationForm } from "@/components/admin/allocation-form";

export default async function BudgetsAdminPage() {
  await requireAdmin();

  const [fiscalYears, categories, departments, allocations] = await Promise.all([
    prisma.fiscalYear.findMany({ orderBy: { yearBE: "desc" } }),
    prisma.budgetCategory.findMany({ orderBy: { name: "asc" } }),
    prisma.department.findMany({ orderBy: { name: "asc" } }),
    prisma.budgetAllocation.findMany({
      include: { department: true, category: true, fiscalYear: true },
      orderBy: [{ fiscalYear: { yearBE: "desc" } }, { department: { name: "asc" } }],
    }),
  ]);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">จัดการงบประมาณ</h1>

      <Card>
        <CardHeader>
          <CardTitle>ปีงบประมาณ</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <FiscalYearForm />
          <ul className="flex flex-wrap gap-2 text-sm text-muted-foreground">
            {fiscalYears.map((f) => (
              <li key={f.id} className="rounded-full border px-3 py-1">
                ปีงบ {f.yearBE} ({formatDateThai(f.startDate)} – {formatDateThai(f.endDate)})
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>หมวดงบประมาณ</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <CategoryForm />
          <ul className="flex flex-wrap gap-2 text-sm text-muted-foreground">
            {categories.map((c) => (
              <li key={c.id} className="rounded-full border px-3 py-1">
                {c.name} ({c.code})
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>สาขาวิชา</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <DepartmentForm />
          <ul className="flex flex-wrap gap-2 text-sm text-muted-foreground">
            {departments.map((d) => (
              <li key={d.id} className="rounded-full border px-3 py-1">
                {d.name} ({d.code})
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>จัดสรรงบประมาณ</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <AllocationForm
            departments={departments}
            categories={categories}
            fiscalYears={fiscalYears}
          />
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>ปีงบ</TableHead>
                <TableHead>สาขาวิชา</TableHead>
                <TableHead>หมวดงบ</TableHead>
                <TableHead className="text-right">จำนวนเงิน</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {allocations.map((a) => (
                <TableRow key={a.id}>
                  <TableCell>{a.fiscalYear.yearBE}</TableCell>
                  <TableCell>{a.department.name}</TableCell>
                  <TableCell>{a.category.name}</TableCell>
                  <TableCell className="text-right">{formatBaht(Number(a.amount))}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
