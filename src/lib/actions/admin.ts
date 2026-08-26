"use server";

import { z } from "zod";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireAdmin } from "@/lib/session";
import { fiscalYearBounds } from "@/lib/fiscal-year";

const fiscalYearSchema = z.object({
  yearBE: z.coerce.number().int().min(2500).max(2700),
});

export async function createFiscalYear(_prev: unknown, formData: FormData) {
  await requireAdmin();
  const parsed = fiscalYearSchema.safeParse({ yearBE: formData.get("yearBE") });
  if (!parsed.success) return { error: "กรุณาระบุปีงบประมาณ (พ.ศ.) ให้ถูกต้อง" };

  const { startDate, endDate } = fiscalYearBounds(parsed.data.yearBE);
  await prisma.fiscalYear.upsert({
    where: { yearBE: parsed.data.yearBE },
    update: {},
    create: { yearBE: parsed.data.yearBE, startDate, endDate },
  });
  revalidatePath("/budgets");
  return { success: true };
}

const categorySchema = z.object({
  name: z.string().min(1),
  code: z
    .string()
    .min(1)
    .transform((s) => s.toUpperCase().replace(/\s+/g, "_")),
});

export async function createBudgetCategory(_prev: unknown, formData: FormData) {
  await requireAdmin();
  const parsed = categorySchema.safeParse({
    name: formData.get("name"),
    code: formData.get("code"),
  });
  if (!parsed.success) return { error: "กรุณากรอกชื่อและรหัสหมวดงบให้ครบถ้วน" };

  await prisma.budgetCategory.create({ data: parsed.data });
  revalidatePath("/budgets");
  return { success: true };
}

const departmentSchema = z.object({
  name: z.string().min(1),
  code: z
    .string()
    .min(1)
    .transform((s) => s.toUpperCase().replace(/\s+/g, "_")),
});

export async function createDepartment(_prev: unknown, formData: FormData) {
  await requireAdmin();
  const parsed = departmentSchema.safeParse({
    name: formData.get("name"),
    code: formData.get("code"),
  });
  if (!parsed.success) return { error: "กรุณากรอกชื่อและรหัสสาขาวิชาให้ครบถ้วน" };

  await prisma.department.create({ data: parsed.data });
  revalidatePath("/budgets");
  return { success: true };
}

const allocationSchema = z.object({
  departmentId: z.string().min(1),
  categoryId: z.string().min(1),
  fiscalYearId: z.string().min(1),
  amount: z.coerce.number().nonnegative(),
});

export async function upsertAllocation(_prev: unknown, formData: FormData) {
  await requireAdmin();
  const parsed = allocationSchema.safeParse({
    departmentId: formData.get("departmentId"),
    categoryId: formData.get("categoryId"),
    fiscalYearId: formData.get("fiscalYearId"),
    amount: formData.get("amount"),
  });
  if (!parsed.success) return { error: "กรุณากรอกข้อมูลการจัดสรรงบให้ครบถ้วน" };

  const { departmentId, categoryId, fiscalYearId, amount } = parsed.data;
  await prisma.budgetAllocation.upsert({
    where: {
      departmentId_categoryId_fiscalYearId: { departmentId, categoryId, fiscalYearId },
    },
    update: { amount },
    create: { departmentId, categoryId, fiscalYearId, amount },
  });
  revalidatePath("/budgets");
  return { success: true };
}
