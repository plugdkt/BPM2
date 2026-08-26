import { ArrowLeftRight } from "lucide-react";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatBaht } from "@/lib/format";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { TransferStatusBadge } from "@/components/status-badges";
import { EmptyState } from "@/components/empty-state";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { TransferRequestForm } from "@/components/transfer-request-form";
import { TransferDecisionButtons } from "@/components/transfer-decision-buttons";

export default async function TransfersPage() {
  const user = await requireUser();

  const [departments, categories, fiscalYears] = await Promise.all([
    prisma.department.findMany({ orderBy: { name: "asc" } }),
    prisma.budgetCategory.findMany({ orderBy: { name: "asc" } }),
    prisma.fiscalYear.findMany({ orderBy: { yearBE: "desc" } }),
  ]);

  const where = user.role === "DEPT_STAFF" ? { departmentId: user.departmentId! } : {};

  const transfers = await prisma.budgetTransfer.findMany({
    where,
    include: {
      department: true,
      fromCategory: true,
      toCategory: true,
      fiscalYear: true,
      requestedBy: true,
      approvedBy: true,
    },
    orderBy: { createdAt: "desc" },
    take: 50,
  });

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">โยกย้ายงบประมาณระหว่างหมวด</h1>

      {user.role !== "EXECUTIVE_VIEWER" && (
        <Card>
          <CardHeader>
            <CardTitle>ยื่นคำขอโยกย้ายงบ</CardTitle>
          </CardHeader>
          <CardContent>
            <TransferRequestForm
              departments={departments}
              categories={categories}
              fiscalYears={fiscalYears}
              lockDepartmentId={user.role === "DEPT_STAFF" ? user.departmentId! : undefined}
            />
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>รายการคำขอโยกย้ายงบ</CardTitle>
        </CardHeader>
        <CardContent>
          {transfers.length === 0 ? (
            <EmptyState
              icon={ArrowLeftRight}
              title="ยังไม่มีคำขอโยกย้ายงบ"
              description="เมื่อมีการยื่นคำขอ รายการจะแสดงที่นี่พร้อมสถานะการอนุมัติ"
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>ปีงบ</TableHead>
                  <TableHead>สาขาวิชา</TableHead>
                  <TableHead>จากหมวด</TableHead>
                  <TableHead>ไปหมวด</TableHead>
                  <TableHead className="text-right">จำนวนเงิน</TableHead>
                  <TableHead>เหตุผล</TableHead>
                  <TableHead>ผู้ขอ</TableHead>
                  <TableHead>สถานะ</TableHead>
                  {user.role === "ADMIN" && <TableHead>การอนุมัติ</TableHead>}
                </TableRow>
              </TableHeader>
              <TableBody>
                {transfers.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell>{t.fiscalYear.yearBE}</TableCell>
                    <TableCell>{t.department.name}</TableCell>
                    <TableCell>{t.fromCategory.name}</TableCell>
                    <TableCell>{t.toCategory.name}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatBaht(Number(t.amount))}
                    </TableCell>
                    <TableCell className="max-w-56 truncate">{t.reason}</TableCell>
                    <TableCell>{t.requestedBy.name}</TableCell>
                    <TableCell>
                      <TransferStatusBadge status={t.status} />
                    </TableCell>
                    {user.role === "ADMIN" && (
                      <TableCell>
                        {t.status === "PENDING" ? (
                          <TransferDecisionButtons transferId={t.id} />
                        ) : (
                          <span className="text-xs text-muted-foreground">
                            {t.approvedBy?.name}
                          </span>
                        )}
                      </TableCell>
                    )}
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
