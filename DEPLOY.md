# Deploy บน IIS ด้วย iisnode

คู่มือนี้เขียนไว้ให้ IT/ผู้ดูแล IIS server ทำตามได้เอง (ผู้เขียนไม่มีสิทธิ์เข้าถึงเครื่อง production โดยตรง) — **ยังไม่เคยรันจริงบน IIS** จึงควรทดสอบทุกขั้นตอนกับ traffic จริงก่อนเปิดใช้งาน โดยเฉพาะส่วน "จุดที่ต้องระวัง" ด้านล่าง

## 1) สิ่งที่ต้องมีบนเครื่อง IIS server ก่อน

- **Node.js LTS** (แนะนำเวอร์ชันเดียวกับที่ใช้ build, `node -v` ต้องเรียกได้จาก PATH ของ System — ไม่ใช่แค่ user account)
- **IIS role** เปิดใช้งานแล้ว
- **URL Rewrite Module** — https://www.iis.net/downloads/microsoft/url-rewrite
- **iisnode** — https://github.com/Azure/iisnode/releases (เลือกตัวที่ตรงกับ Windows/Node เวอร์ชันบนเครื่อง)
- **Git** (หรือวิธีอื่นในการนำโค้ดขึ้นเครื่อง เช่น zip)
- เข้าถึง PostgreSQL server ที่จะใช้เป็น production DB ได้จากเครื่องนี้ (network/firewall)

## 2) นำโค้ดขึ้นเครื่องและตั้งค่า environment

```powershell
git clone https://github.com/plugdkt/BPM.git C:\sites\bpm
cd C:\sites\bpm
```

สร้างไฟล์ `.env.production` ที่ root ของโปรเจกต์ (**ห้าม commit เข้า git** — ไฟล์นี้ถูก gitignore ไว้แล้ว):

```
DATABASE_URL="postgresql://user:password@db-server-host:5432/bpm?schema=public"
AUTH_SECRET="<สร้างด้วยคำสั่งด้านล่าง>"
```

สร้างค่า `AUTH_SECRET` แบบสุ่ม:

```powershell
npx auth secret
# หรือ
node -e "console.log(require('crypto').randomBytes(32).toString('base64'))"
```

> Next.js จะ copy `.env.production` เข้าไปใน `.next/standalone/` ให้อัตโนมัติตอน build (เป็นพฤติกรรมมาตรฐานของ `output: "standalone"`) ค่าที่ตั้งในไฟล์นี้จะถูกอ่านตอน runtime ทุกครั้งที่ server.js เริ่มทำงาน

## 3) รัน migration เข้า production database

```powershell
npm ci
npx prisma migrate deploy
```

`prisma migrate deploy` ใช้กับ production โดยเฉพาะ (ต่างจาก `migrate dev` ที่ใช้ตอนพัฒนา) — ใช้ `.env.production` ที่สร้างไว้เป็น connection string

ถ้าต้องการข้อมูลตั้งต้น (สาขาวิชา/หมวดงบ/บัญชีทดสอบ) ให้รัน `npm run db:seed` ด้วย — **แต่ควรแก้รหัสผ่านบัญชีทดสอบใน `prisma/seed.ts` ก่อน** (ปัจจุบันคือ `password123` ซึ่งเหมาะกับ dev เท่านั้น)

## 4) Build และแพ็กเป็นโฟลเดอร์สำหรับ iisnode

```powershell
.\scripts\build-for-iis.ps1
```

สคริปต์นี้จะ `npm ci`, `prisma generate`, `next build`, แล้ว copy `public/`, `.next/static/`, และ `web.config` เข้าไปรวมกับ `.next/standalone/server.js` ให้ครบ — ผลลัพธ์คือโฟลเดอร์ `.next/standalone` ที่พร้อม deploy ทั้งก้อน (มี `node_modules` ที่จำเป็นครบ ไม่ต้อง `npm install` ซ้ำที่ปลายทาง)

## 5) ตั้งค่า IIS Site

1. เปิด IIS Manager → สร้าง **Application Pool** ใหม่ ตั้งค่า **.NET CLR version = No Managed Code**
2. สร้าง **Site** ใหม่ ชี้ **Physical path** ไปที่ `C:\sites\bpm\.next\standalone` (โฟลเดอร์ที่มี `server.js` และ `web.config` อยู่ระดับเดียวกัน) ผูกกับ Application Pool ที่สร้างไว้
3. ตั้งค่า binding (port/host header/HTTPS cert) ตามที่ต้องการ
4. ให้สิทธิ์ **Read & Execute** แก่ user ของ Application Pool (`IIS AppPool\<ชื่อ pool>`) บนโฟลเดอร์ `.next\standalone` ทั้งหมด และสิทธิ์ **Write/Modify** บนโฟลเดอร์นี้ด้วย (iisnode ต้องสร้างโฟลเดอร์ log ชื่อ `iisnode` เอง)
5. เปิดเว็บ ตรวจว่าโหลดหน้า login ได้ และ login ทดสอบด้วยบัญชีที่ seed ไว้

## 6) Deploy ครั้งถัดไป (อัปเดตโค้ด)

```powershell
cd C:\sites\bpm
git pull
.\scripts\build-for-iis.ps1
```

จากนั้น recycle Application Pool (IIS Manager หรือ `Restart-WebAppPool -Name "<ชื่อ pool>"`) เพื่อให้ iisnode เริ่ม process ใหม่จากโค้ดล่าสุด — ต้องรัน `npx prisma migrate deploy` ทุกครั้งที่ `prisma/schema.prisma` เปลี่ยนด้วย

## จุดที่ต้องระวัง (ยังไม่ได้ทดสอบบน IIS จริง)

- **Streaming/RSC**: Next.js App Router ส่ง response แบบ streaming เป็นค่าเริ่มต้น ถ้า iisnode buffer response ไว้ (ไม่ flush ทันที) หน้าเว็บอาจโหลดช้าผิดปกติหรือค้าง — ใน `web.config` ตั้ง `flushResponse="true"` ไว้แล้วเพื่อลดปัญหานี้ แต่ควรทดสอบการนำทางระหว่างหน้า (client-side navigation) และการ submit ฟอร์ม (server actions) ให้ครบก่อนใช้งานจริง ถ้ายังมีปัญหาค้าง/ช้า ทางเลือกที่มั่นคงกว่าคือเปลี่ยนไปใช้ IIS เป็น reverse proxy (ARR) หน้า Node process ที่รันด้วย PM2/Windows Service แทน iisnode
- **Prisma engine binary**: ถ้าเครื่องที่ build (`build-for-iis.ps1`) กับเครื่อง IIS server เป็น Windows คนละเวอร์ชัน/สถาปัตยกรรมกัน query engine ของ Prisma อาจใช้ไม่ได้ — แนะนำให้ build บนเครื่อง IIS server เอง (ตามคู่มือนี้) ไม่ใช่ build เครื่องอื่นแล้ว copy มา
- **Log rotation**: `logDirectory="iisnode"` ใน `web.config` จะสร้างไฟล์ log สะสมเรื่อย ๆ ควรตั้ง cleanup/rotation เป็นระยะ
- **Auth cookie ผ่าน HTTPS**: ถ้า production ใช้ HTTPS (แนะนำอย่างยิ่งเพราะมีการ login ด้วยรหัสผ่าน) Auth.js จะตั้งชื่อ cookie เป็น `__Secure-*` โดยอัตโนมัติเมื่อรันบน HTTPS — ตรวจสอบว่า IIS binding มี HTTPS/SSL cert ผูกไว้จริง ไม่งั้นการ login จะวนกลับไม่ผ่าน
