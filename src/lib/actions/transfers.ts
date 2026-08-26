"use server";

import { z } from "zod";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireAdmin, requireUser } from "@/lib/session";
import { getAvailableBalance } from "@/lib/budget";

const requestSchema = z.object({
  departmentId: z.string().min(1),
  fiscalYearId: z.string().min(1),
  fromCategoryId: z.string().min(1),
  toCategoryId: z.string().min(1),
  amount: z.coerce.number().positive(),
  reason: z.string().min(1),
});

export async function requestTransfer(_prev: unknown, formData: FormData) {
  const user = await requireUser();
  if (user.role === "EXECUTIVE_VIEWER") return { error: "ไม่มีสิทธิ์ยื่นคำขอโยกย้ายงบ" };

  const parsed = requestSchema.safeParse({
    departmentId: formData.get("departmentId"),
    fiscalYearId: formData.get("fiscalYearId"),
    fromCategoryId: formData.get("fromCategoryId"),
    toCategoryId: formData.get("toCategoryId"),
    amount: formData.get("amount"),
    reason: formData.get("reason"),
  });
  if (!parsed.success) return { error: "กรุณากรอกข้อมูลคำขอโยกย้ายงบให้ครบถ้วน" };

  const { departmentId, fiscalYearId, fromCategoryId, toCategoryId, amount, reason } = parsed.data;

  if (fromCategoryId === toCategoryId) {
    return { error: "หมวดต้นทางและปลายทางต้องไม่ใช่หมวดเดียวกัน" };
  }
  if (user.role === "DEPT_STAFF" && departmentId !== user.departmentId) {
    return { error: "คุณสามารถยื่นคำขอของสาขาวิชาตัวเองเท่านั้น" };
  }

  const available = await getAvailableBalance({ fiscalYearId, departmentId, categoryId: fromCategoryId });
  if (amount > available) {
    return { error: `ยอดคงเหลือในหมวดต้นทางไม่พอ (คงเหลือ ${available.toLocaleString("th-TH")} บาท)` };
  }

  await prisma.budgetTransfer.create({
    data: {
      departmentId,
      fiscalYearId,
      fromCategoryId,
      toCategoryId,
      amount,
      reason,
      requestedById: user.id,
    },
  });

  revalidatePath("/transfers");
  return { success: true };
}

const decisionSchema = z.object({
  transferId: z.string().min(1),
  decision: z.enum(["APPROVED", "REJECTED"]),
});

export async function decideTransfer(_prev: unknown, formData: FormData) {
  const admin = await requireAdmin();

  const parsed = decisionSchema.safeParse({
    transferId: formData.get("transferId"),
    decision: formData.get("decision"),
  });
  if (!parsed.success) return { error: "ข้อมูลคำขอไม่ถูกต้อง" };

  const { transferId, decision } = parsed.data;

  const transfer = await prisma.budgetTransfer.findUnique({ where: { id: transferId } });
  if (!transfer) return { error: "ไม่พบคำขอโยกย้ายงบ" };
  if (transfer.status !== "PENDING") return { error: "คำขอนี้ถูกตัดสินใจไปแล้ว" };

  if (decision === "APPROVED") {
    const available = await getAvailableBalance({
      fiscalYearId: transfer.fiscalYearId,
      departmentId: transfer.departmentId,
      categoryId: transfer.fromCategoryId,
    });
    if (Number(transfer.amount) > available) {
      return { error: `ยอดคงเหลือในหมวดต้นทางไม่พอแล้ว (คงเหลือ ${available.toLocaleString("th-TH")} บาท)` };
    }
  }

  await prisma.budgetTransfer.update({
    where: { id: transferId },
    data: { status: decision, approvedById: admin.id, decidedAt: new Date() },
  });

  revalidatePath("/transfers");
  revalidatePath("/dashboard");
  revalidatePath("/reports");
  return { success: true };
}
