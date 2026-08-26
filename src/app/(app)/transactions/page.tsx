import { Receipt } from "lucide-react";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatBaht, formatDateThai } from "@/lib/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { TransactionTypeBadge } from "@/components/status-badges";
import { EmptyState } from "@/components/empty-state";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { TransactionForm } from "@/components/transaction-form";

export default async function TransactionsPage() {
  const user = await requireUser();

  const [departments, categories, fiscalYears] = await Promise.all([
    prisma.department.findMany({ orderBy: { name: "asc" } }),
    prisma.budgetCategory.findMany({ orderBy: { name: "asc" } }),
    prisma.fiscalYear.findMany({ orderBy: { yearBE: "desc" } }),
  ]);

  const departmentFilter =
    user.role === "DEPT_STAFF" ? { allocation: { departmentId: user.departmentId! } } : {};

  const transactions = await prisma.transaction.findMany({
    where: departmentFilter,
    include: { allocation: { include: { department: true, category: true } }, createdBy: true },
    orderBy: { date: "desc" },
    take: 50,
  });

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">บันทึกรายรับ-รายจ่าย</h1>

      <Card>
        <CardHeader>
          <CardTitle>เพิ่มรายการใหม่</CardTitle>
        </CardHeader>
        <CardContent>
          <TransactionForm
            departments={departments}
            categories={categories}
            fiscalYears={fiscalYears}
            lockDepartmentId={user.role === "DEPT_STAFF" ? user.departmentId! : undefined}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>รายการล่าสุด</CardTitle>
        </CardHeader>
        <CardContent>
          {transactions.length === 0 ? (
            <EmptyState
              icon={Receipt}
              title="ยังไม่มีรายการ"
              description="เพิ่มรายรับหรือรายจ่ายแรกด้วยฟอร์มด้านบน"
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>วันที่</TableHead>
                  <TableHead>สาขาวิชา</TableHead>
                  <TableHead>หมวดงบ</TableHead>
                  <TableHead>ประเภท</TableHead>
                  <TableHead>รายละเอียด</TableHead>
                  <TableHead className="text-right">จำนวนเงิน</TableHead>
                  <TableHead>บันทึกโดย</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transactions.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell className="whitespace-nowrap">{formatDateThai(t.date)}</TableCell>
                    <TableCell>{t.allocation.department.name}</TableCell>
                    <TableCell>{t.allocation.category.name}</TableCell>
                    <TableCell>
                      <TransactionTypeBadge type={t.type} />
                    </TableCell>
                    <TableCell className="max-w-64 truncate">{t.description}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatBaht(Number(t.amount))}
                    </TableCell>
                    <TableCell>{t.createdBy.name}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
