import { PrismaClient } from "@prisma/client";
import bcrypt from "bcryptjs";
import { currentFiscalYearBE, fiscalYearBounds } from "../src/lib/fiscal-year";

const prisma = new PrismaClient();

async function main() {
  const departments = await Promise.all(
    [
      { name: "สาขาวิชาวิทยาการคอมพิวเตอร์", code: "CS" },
      { name: "สาขาวิชาเทคโนโลยีสารสนเทศ", code: "IT" },
      { name: "สาขาวิชาวิศวกรรมซอฟต์แวร์", code: "SE" },
    ].map((d) => prisma.department.upsert({ where: { code: d.code }, update: {}, create: d } ))
  );

  const categories = await Promise.all(
    [
      { name: "ค่าตอบแทน", code: "COMPENSATION" },
      { name: "ค่าใช้สอย", code: "OPERATING" },
      { name: "ค่าวัสดุ", code: "SUPPLIES" },
      { name: "ค่าครุภัณฑ์", code: "EQUIPMENT" },
    ].map((c) => prisma.budgetCategory.upsert({ where: { code: c.code }, update: {}, create: c } ))
  );

  const yearBE = currentFiscalYearBE();
  const { startDate, endDate } = fiscalYearBounds(yearBE);
  const fiscalYear = await prisma.fiscalYear.upsert({
    where: { yearBE },
    update: {},
    create: { yearBE, startDate, endDate },
  });

  for (const dept of departments) {
    for (const [i, cat] of categories.entries()) {
      await prisma.budgetAllocation.upsert({
        where: {
          departmentId_categoryId_fiscalYearId: {
            departmentId: dept.id,
            categoryId: cat.id,
            fiscalYearId: fiscalYear.id,
          },
        },
        update: {},
        create: {
          departmentId: dept.id,
          categoryId: cat.id,
          fiscalYearId: fiscalYear.id,
          amount: 100000 + i * 50000,
        },
      });
    }
  }

  const passwordHash = await bcrypt.hash("password123", 10);

  await prisma.user.upsert({
    where: { email: "admin@bpm.local" },
    update: {},
    create: {
      name: "ผู้ดูแลระบบ",
      email: "admin@bpm.local",
      passwordHash,
      role: "ADMIN",
    },
  });

  await prisma.user.upsert({
    where: { email: "cs.staff@bpm.local" },
    update: {},
    create: {
      name: "เจ้าหน้าที่สาขา CS",
      email: "cs.staff@bpm.local",
      passwordHash,
      role: "DEPT_STAFF",
      departmentId: departments[0].id,
    },
  });

  await prisma.user.upsert({
    where: { email: "it.staff@bpm.local" },
    update: {},
    create: {
      name: "เจ้าหน้าที่สาขา IT",
      email: "it.staff@bpm.local",
      passwordHash,
      role: "DEPT_STAFF",
      departmentId: departments[1].id,
    },
  });

  await prisma.user.upsert({
    where: { email: "viewer@bpm.local" },
    update: {},
    create: {
      name: "ผู้บริหาร (ดูอย่างเดียว)",
      email: "viewer@bpm.local",
      passwordHash,
      role: "EXECUTIVE_VIEWER",
    },
  });

  console.log("Seed complete. Fiscal year:", yearBE);
  console.log("Test accounts (password: password123):");
  console.log("  admin@bpm.local (ADMIN)");
  console.log("  cs.staff@bpm.local (DEPT_STAFF - CS)");
  console.log("  it.staff@bpm.local (DEPT_STAFF - IT)");
  console.log("  viewer@bpm.local (EXECUTIVE_VIEWER)");
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
