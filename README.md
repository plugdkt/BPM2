# BPM — ระบบบริหารจัดการงบประมาณสาขาวิชา

ระบบเว็บสำหรับติดตามงบประมาณของแต่ละสาขาวิชา: ได้รับจัดสรรเท่าไหร่ ใช้ไปเท่าไหร่ คงเหลือเท่าไหร่ ออกรายงานรายเดือน/รายไตรมาสตามปีงบประมาณราชการไทย และโยกย้ายงบระหว่างหมวดได้ (ผ่านขั้นตอนอนุมัติ)

## Stack

Next.js (App Router) + TypeScript · PostgreSQL + Prisma · Auth.js (credentials) · Tailwind + shadcn/ui · Recharts

## เริ่มต้นใช้งาน (Dev)

```bash
docker compose up -d        # เริ่ม Postgres
npm install
npx prisma migrate dev      # สร้างตารางในฐานข้อมูล
npm run db:seed             # ข้อมูลตัวอย่าง + บัญชีทดสอบ
npm run dev
```

เปิด [http://localhost:3000](http://localhost:3000)

## บัญชีทดสอบ (หลัง db:seed)

รหัสผ่านทั้งหมด: `password123`

| อีเมล | บทบาท |
|---|---|
| admin@bpm.local | ADMIN — จัดการงบ, อนุมัติการโยกย้าย, ดูทุกสาขา |
| cs.staff@bpm.local | DEPT_STAFF — สาขาวิทยาการคอมพิวเตอร์ |
| it.staff@bpm.local | DEPT_STAFF — สาขาเทคโนโลยีสารสนเทศ |
| viewer@bpm.local | EXECUTIVE_VIEWER — ดูอย่างเดียว ทุกสาขา |

## คำสั่งที่ใช้บ่อย

```bash
npm run db:studio   # เปิด Prisma Studio ดู/แก้ข้อมูลตรง ๆ
npm run db:migrate  # สร้าง migration ใหม่หลังแก้ schema.prisma
npm run build        # build + type check
```

## โครงสร้างหลัก

- `prisma/schema.prisma` — data model (Department, FiscalYear, BudgetCategory, BudgetAllocation, Transaction, BudgetTransfer)
- `src/lib/budget.ts` — คำนวณยอดคงเหลือแบบสดจาก allocation + transfer(approved) + transaction
- `src/lib/fiscal-year.ts` — ปีงบประมาณราชการไทย (ต.ค.–ก.ย.) และไตรมาส
- `src/lib/actions/*` — server actions (admin, transactions, transfers) พร้อมตรวจสิทธิ์ตาม role
- `src/middleware.ts` — บังคับ login + จำกัดสิทธิ์เข้าหน้า `/budgets` เฉพาะ ADMIN
