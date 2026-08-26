import { Prisma } from "@prisma/client";
import { prisma } from "@/lib/prisma";

export type AllocationSummary = {
  allocationId: string;
  departmentId: string;
  departmentName: string;
  categoryId: string;
  categoryName: string;
  allocated: number;
  transferredIn: number;
  transferredOut: number;
  expense: number;
  income: number;
  balance: number;
};

function toNumber(d: Prisma.Decimal | number | null | undefined): number {
  if (d === null || d === undefined) return 0;
  return typeof d === "number" ? d : d.toNumber();
}

/**
 * รวมยอด allocated / transfer / expense / income ต่อ allocation
 * เพื่อคำนวณยอดคงเหลือแบบสด (ไม่เก็บ balance เป็นค่านิ่ง)
 */
export async function getAllocationSummaries(params: {
  fiscalYearId: string;
  departmentId?: string;
}): Promise<AllocationSummary[]> {
  const { fiscalYearId, departmentId } = params;

  const allocations = await prisma.budgetAllocation.findMany({
    where: {
      fiscalYearId,
      ...(departmentId ? { departmentId } : {}),
    },
    include: {
      department: true,
      category: true,
      transactions: true,
    },
  });

  const transfers = await prisma.budgetTransfer.findMany({
    where: {
      fiscalYearId,
      status: "APPROVED",
      ...(departmentId ? { departmentId } : {}),
    },
  });

  return allocations.map((a) => {
    const expense = a.transactions
      .filter((t) => t.type === "EXPENSE")
      .reduce((sum, t) => sum + toNumber(t.amount), 0);
    const income = a.transactions
      .filter((t) => t.type === "INCOME")
      .reduce((sum, t) => sum + toNumber(t.amount), 0);

    const transferredIn = transfers
      .filter((t) => t.departmentId === a.departmentId && t.toCategoryId === a.categoryId)
      .reduce((sum, t) => sum + toNumber(t.amount), 0);
    const transferredOut = transfers
      .filter((t) => t.departmentId === a.departmentId && t.fromCategoryId === a.categoryId)
      .reduce((sum, t) => sum + toNumber(t.amount), 0);

    const allocated = toNumber(a.amount);
    const balance = allocated + transferredIn - transferredOut - expense + income;

    return {
      allocationId: a.id,
      departmentId: a.departmentId,
      departmentName: a.department.name,
      categoryId: a.categoryId,
      categoryName: a.category.name,
      allocated,
      transferredIn,
      transferredOut,
      expense,
      income,
      balance,
    };
  });
}

/** ยอดคงเหลือที่โอนได้จากหมวดหนึ่งของสาขาหนึ่ง ในปีงบที่กำหนด */
export async function getAvailableBalance(params: {
  fiscalYearId: string;
  departmentId: string;
  categoryId: string;
}): Promise<number> {
  const summaries = await getAllocationSummaries({
    fiscalYearId: params.fiscalYearId,
    departmentId: params.departmentId,
  });
  const match = summaries.find((s) => s.categoryId === params.categoryId);
  return match?.balance ?? 0;
}
