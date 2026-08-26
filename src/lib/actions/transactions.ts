"use server";

import { z } from "zod";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";

const transactionSchema = z.object({
  departmentId: z.string().min(1),
  categoryId: z.string().min(1),
  fiscalYearId: z.string().min(1),
  type: z.enum(["EXPENSE", "INCOME"]),
  amount: z.coerce.number().positive(),
  description: z.string().min(1),
  referenceNo: z.string().optional(),
  date: z.coerce.date(),
});

export async function createTransaction(_prev: unknown, formData: FormData) {
  const user = await requireUser();
  if (user.role === "EXECUTIVE_VIEWER") return { error: "ไม่มีสิทธิ์บันทึกรายการ" };

  const parsed = transactionSchema.safeParse({
    departmentId: formData.get("departmentId"),
    categoryId: formData.get("categoryId"),
    fiscalYearId: formData.get("fiscalYearId"),
    type: formData.get("type"),
    amount: formData.get("amount"),
    description: formData.get("description"),
    referenceNo: formData.get("referenceNo") || undefined,
    date: formData.get("date"),
  });
  if (!parsed.success) return { error: "กรุณากรอกข้อมูลรายการให้ครบถ้วนและถูกต้อง" };

  const { departmentId, categoryId, fiscalYearId, ...rest } = parsed.data;

  if (user.role === "DEPT_STAFF" && departmentId !== user.departmentId) {
    return { error: "คุณสามารถบันทึกรายการของสาขาวิชาตัวเองเท่านั้น" };
  }

  const allocation = await prisma.budgetAllocation.findUnique({
    where: {
      departmentId_categoryId_fiscalYearId: { departmentId, categoryId, fiscalYearId },
    },
  });
  if (!allocation) {
    return { error: "ยังไม่มีการจัดสรรงบประมาณในหมวดนี้ กรุณาติดต่อผู้ดูแลระบบ" };
  }

  await prisma.transaction.create({
    data: {
      allocationId: allocation.id,
      createdById: user.id,
      ...rest,
    },
  });

  revalidatePath("/transactions");
  revalidatePath("/dashboard");
  revalidatePath("/reports");
  return { success: true };
}
