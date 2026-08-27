# สเปกระบบ: ระบบบริหารจัดการงบประมาณสาขาวิชา (BPM)

> เวอร์ชันนี้ปรับ stack จากตัวต้นแบบเดิม (Next.js + Prisma + PostgreSQL) มาเป็น **PHP + MariaDB + IIS** ตามทรัพยากรที่มีจริง ฟีเจอร์และกติกาทางธุรกิจอ้างอิงจากตัวต้นแบบเดิม, คู่มือ [BPM_UI_UX_GUIDELINES.md](BPM_UI_UX_GUIDELINES.md) และ [sso_integration_guide.md](sso_integration_guide.md) (คู่มือเชื่อมต่อ MEDSCI ACC SSO ของคณะวิทยาศาสตร์การแพทย์ ม.พะเยา)

---

## 1. ภาพรวมและเป้าหมาย

ระบบเว็บสำหรับติดตามงบประมาณของแต่ละสาขาวิชาในคณะ/มหาวิทยาลัย ตอบคำถาม 3 ข้อหลักตลอดเวลา:

1. แต่ละสาขาได้รับจัดสรรงบเท่าไหร่ (แยกตามหมวด)
2. ใช้ไปแล้วเท่าไหร่ คงเหลือเท่าไหร่ (สดตามเวลาจริง)
3. มีการโยกย้ายงบระหว่างหมวดหรือไม่ ผ่านการอนุมัติหรือยัง

ออกรายงานรายเดือน/รายไตรมาสตามปีงบประมาณราชการไทย และรองรับ export Excel/PDF ผู้ใช้งานล็อกอินด้วยบัญชี **UP Account** ผ่านระบบ SSO กลางของคณะ (MEDSCI ACC) ไม่มีการสร้าง/เก็บรหัสผ่านแยกในระบบนี้

**ไม่อยู่ในขอบเขต (Out of scope) ของเวอร์ชันแรก:** ระบบจัดซื้อจัดจ้าง, ระบบเบิกจ่ายเงินเดือน, การเชื่อมต่อระบบบัญชีภายนอก (เช่น GFMIS) — เก็บไว้พิจารณาเป็นเฟสถัดไป

**สถานะของ "การอนุมัติ" ในระบบนี้ (ยืนยันกับลูกค้าแล้ว):** การกดอนุมัติ/ไม่อนุมัติคำขอโยกย้ายงบในระบบเป็นการ**บันทึกผลไว้เพื่อติดตาม (monitoring)** เท่านั้น ไม่ใช่กระบวนการอนุมัติที่มีผลทางการแทนขั้นตอนอนุมัติจริงของมหาวิทยาลัยซึ่งทำอยู่นอกระบบอยู่แล้ว (เช่น ลายเซ็นบนกระดาษ) ดังนั้นออกแบบเป็น **ขั้นตอนเดียว** (ADMIN กด อนุมัติ/ไม่อนุมัติ) โดยเจตนา ไม่ต้องรองรับ multi-level approval workflow

---

## 2. ผู้ใช้งานและสิทธิ์ (Roles)

| Role | คำอธิบาย | สิทธิ์ |
|---|---|---|
| **ADMIN** | ผู้ดูแลระบบ/งานงบประมาณกลาง | จัดการงบจัดสรรทุกสาขา, ตั้งค่าปีงบ/หมวดงบ/สาขา, อนุมัติ/ไม่อนุมัติคำขอโยกย้ายงบ, ดูรายงานทุกสาขา, จัดการผู้ใช้ (กำหนด role/สาขาให้บัญชีที่ล็อกอินผ่าน SSO) |
| **DEPT_STAFF** | เจ้าหน้าที่ประจำสาขาวิชา | บันทึกรายการเบิกจ่าย/รายรับของสาขาตัวเอง, ยื่นคำขอโยกย้ายงบของสาขาตัวเอง, ดูรายงานเฉพาะสาขาตัวเอง |
| **EXECUTIVE_VIEWER** | ผู้บริหาร/ดูภาพรวม | ดูอย่างเดียว (read-only) ทุกสาขา ทุกรายงาน ไม่มีสิทธิ์แก้ไข/บันทึกรายการ |

กติกาการเข้าถึงข้อมูล:
- `DEPT_STAFF` ต้องผูกกับ `department_id` เดียวเท่านั้น มองเห็น/แก้ไขได้เฉพาะข้อมูลของสาขาตัวเอง
- `ADMIN` และ `EXECUTIVE_VIEWER` ไม่ผูกกับสาขา มองเห็นได้ทุกสาขา
- ทุก endpoint/หน้าที่มีผลต่อข้อมูล (POST/insert/update) ต้องเช็ค role + ownership ฝั่ง server เสมอ ห้ามเชื่อ role ที่ส่งมาจาก client
- **role/สาขาเป็นเรื่องที่ BPM ต้องกำหนดเอง** — MEDSCI ACC ยืนยันได้แค่ "คนนี้คือใคร และเป็นบุคลากรคณะจริงหรือไม่" เท่านั้น ไม่รู้จักหมวด role ของ BPM ดังนั้นบัญชีที่ล็อกอินผ่าน SSO ครั้งแรกจะยังไม่มีสิทธิ์ใช้งานฟีเจอร์ใดๆ จนกว่า ADMIN จะเข้าไปกำหนด role + สาขาให้ที่หน้า `/admin/users.php` (ดูรายละเอียด flow ในข้อ 3.4)
- **ข้อจำกัดที่ต้องยืนยันกับผู้ใช้จริง**: ผู้ใช้ 1 คนผูกกับ `department_id` เดียวเท่านั้น (ดู schema ข้อ 5) — ถ้ามีเจ้าหน้าที่ที่ต้องดูแลงบมากกว่า 1 สาขาจริงในทางปฏิบัติ ระบบเวอร์ชันนี้ยังไม่รองรับ (ต้องสร้างบัญชีแยกหรือปรับ schema เพิ่ม ถ้าจำเป็น — ดูคำถามเปิดข้อ 13)

---

## 3. การยืนยันตัวตน (Authentication) — เชื่อมต่อ MEDSCI ACC SSO

อ้างอิงเต็มจาก [sso_integration_guide.md](sso_integration_guide.md) เลือกใช้ **วิธีที่ 1: SSO Redirect** (วิธีที่คู่มือแนะนำ) เพราะเป็นระบบพัฒนาใหม่ ไม่มีหน้า login เดิมที่ต้องรักษาไว้ และไม่ต้องรับ/ส่งผ่านรหัสผ่านของผู้ใช้เองแม้แต่ขั้นตอนเดียว

### 3.1 ภาพรวม flow

```
ผู้ใช้ -> login.php (BPM) --redirect--> msc_acc/sso/login.php (กรอก UP Account)
                                              |
                                    (สำเร็จ) redirect กลับ
                                              v
                                sso_callback.php (BPM) --POST verify token--> msc_acc/api/verify.php
                                              |
                                (status=success) จับคู่/สร้าง local user -> เซ็ต session -> เข้า dashboard
```

### 3.2 การลงทะเบียนระบบย่อยกับ MEDSCI ACC (ทำครั้งเดียว ก่อนเริ่ม dev/deploy)

ต้องให้ผู้ดูแลระบบ (เจ้าของบัญชีนี้เอง หรือแอดมิน MEDSCI ACC) ลงทะเบียนที่ `https://www.medsci.up.ac.th/msc_acc/admin/clients.php`:

| ค่าที่ต้องกำหนด | ค่าที่แนะนำสำหรับ BPM |
|---|---|
| ชื่อระบบ | ระบบบริหารจัดการงบประมาณสาขาวิชา (BPM) |
| Client ID | `BPM` — **ลงทะเบียนแล้วจริง** (client_secret ได้รับแล้วเช่นกัน เก็บไว้ใน `config/config.php` ที่ .gitignore ไว้ ไม่ใช่ในสเปกนี้หรือไฟล์ template ใดๆ) |
| Client Secret | กดสุ่มจากแผงควบคุม แล้วเก็บเข้า `config/config.php` (นอก webroot) **ห้าม commit เข้า git** |
| Redirect URI | `https://www.medsci.up.ac.th/bpm/sso_callback.php` (สมมติ deploy เป็น subpath `/bpm/` บนโดเมนคณะ `https://www.medsci.up.ac.th` ตามรูปแบบตัวอย่างในคู่มือ SSO — **ต้องยืนยัน path จริงกับผู้ดูแลระบบเครื่อง IIS อีกครั้งก่อนลงทะเบียน**) ต้องระบุ URL เต็มตรงกับที่ deploy จริง ห้ามใช้ wildcard |

### 3.3 หน้า Login และ Callback ของ BPM

- **`public/login.php`**: ไม่มีฟอร์มกรอก username/password ในระบบ BPM เอง — กดปุ่ม "เข้าสู่ระบบด้วยบัญชี UP Account" แล้ว `header("Location: ...")` ไปยัง `msc_acc/sso/login.php` พร้อม `client_id`, `redirect_uri` และ **`state`** ตามตัวอย่างโค้ดล่าสุดในคู่มือ SSO ข้อ "วิธีที่ 1" ขั้นตอน 1 — สุ่ม nonce ด้วย `bin2hex(random_bytes(16))` เก็บไว้ใน `$_SESSION['sso_state']` ก่อน redirect ทุกครั้ง (คู่มือกำหนดให้ระบบย่อยทำเองฝั่ง client เพื่อป้องกัน login CSRF — ดูข้อ 3.6)
- **`public/sso_callback.php`**: รับ `?token=...&state=...` จาก MEDSCI ACC — **ต้องตรวจสอบ `state` ด้วย `hash_equals($_SESSION['sso_state'], $_GET['state'])` เป็นขั้นตอนแรกก่อนทำอะไรทั้งสิ้น** (ไม่ตรง/ไม่มีค่า → redirect ไปหน้า error ตามข้อ 3.8 ทันที) แล้ว `unset($_SESSION['sso_state'])` ทิ้งเพื่อกันใช้ซ้ำ จากนั้นค่อย POST token ไปตรวจสอบที่ `msc_acc/api/verify.php` ด้วย `client_id` + `client_secret` (เก็บใน `config/config.php` ไม่ hardcode ในไฟล์นี้) แล้วทำตาม flow ข้อ 3.4 — **verify สำเร็จแล้วให้เรียก `session_regenerate_id(true)` ทันทีก่อนเซ็ตค่า `$_SESSION` อื่นๆ** (ป้องกัน session fixation เพราะ session id เดิมถูกสร้างไว้ตั้งแต่ก่อนยืนยันตัวตน) แล้วค่อย `header("Location: index.php")` ทันที (Post/Redirect/Get pattern ตามคู่มือข้อ 6.6) เพื่อไม่ให้ URL ที่มี token ค้างอยู่ใน browser history

### 3.4 การจับคู่ผู้ใช้ SSO กับบัญชีในระบบ BPM (Local Provisioning)

เมื่อ verify token สำเร็จ จะได้ข้อมูลโปรไฟล์ (`user_id`, `username`, `name`, `pos_name`, `div_name`, `email`) กลับมา ให้ทำตามนี้ (เขียนเป็นฟังก์ชันกลาง `src/lib/auth.php`):

1. ค้นหาในตาราง `users` local ด้วย `sso_username = $result['user']['username']`
2. **ถ้าพบ** และ `role IS NOT NULL` → อัปเดต `name`/`email` ให้ตรงล่าสุดจาก SSO (เผื่อเปลี่ยนชื่อ/อีเมล), เซ็ต `last_login_at = NOW()`, สร้าง PHP session แล้วพาเข้า dashboard ตาม role
3. **ถ้าพบ** แต่ `role IS NULL` (ยังไม่ถูกกำหนดสิทธิ์) → พาไปหน้า "รอผู้ดูแลระบบกำหนดสิทธิ์การใช้งาน" ไม่ให้เข้าฟีเจอร์ใดๆ
4. **ถ้าไม่พบ** → insert แถวใหม่ในตาราง `users` (เก็บ `sso_username`, `sso_user_id`, `name`, `email`, `pos_name`, `div_name` จาก SSO, `role = NULL`) แล้วพาไปหน้ารอสิทธิ์เหมือนข้อ 3 — จากนั้น ADMIN เข้ามากำหนด role/สาขาที่ `/admin/users.php` ภายหลัง
5. **ถ้า verify ไม่สำเร็จ** (`status !== 'success'`) → แสดงข้อความ error จาก `$result['message']` ไม่ต้อง die ด้วย stack trace, log รายละเอียดไว้ฝั่ง server สำหรับ debug

> เหตุผลที่ไม่ auto-assign role: SSO บอกได้แค่ตำแหน่ง/สังกัดในมหาวิทยาลัย (`pos_name`, `div_name`) แต่ไม่รู้ว่าคนนี้ควรมีสิทธิ์อะไรใน BPM (เช่น เป็นเจ้าหน้าที่สาขาไหน) — จุดนี้เป็น business decision ที่ ADMIN ของ BPM ต้องเป็นคนตัดสินใจเอง ป้องกันคนนอกกลุ่มที่ตั้งใจได้สิทธิ์เกินจำเป็นโดยไม่มีใครอนุมัติ

### 3.5 โหมดพัฒนา/ทดสอบ (Developer Bypass)

ตามคู่มือ SSO ข้อ 5 ใช้บัญชีทดสอบ `admin.dev` / `dev1234` ยิงผ่าน flow ปกติ (ต้องให้เครื่อง dev เชื่อมต่ออินเทอร์เน็ตออกไปหา `www.medsci.up.ac.th` ได้ — ไม่ใช่ mock local) ระบบกลางจะจำลองผลสำเร็จและจับคู่โปรไฟล์เป็น `wittaya.su` ให้อัตโนมัติ ใช้บัญชีนี้ seed สิทธิ์ `ADMIN` ในเครื่อง dev เพื่อไปกำหนดสิทธิ์บัญชีทดสอบอื่นๆ ต่อได้

### 3.6 ข้อควรระวังด้านความปลอดภัยเฉพาะ SSO (จากคู่มือข้อ 6 + เพิ่มเติม)

- `client_secret` เก็บใน `config/config.php` (นอก webroot, ไม่ commit เข้า git) เท่านั้น ห้ามอยู่ใน JavaScript ฝั่ง client หรือ URL
- ทุก endpoint ที่เกี่ยวกับ SSO (`login.php`, `sso_callback.php`, การเรียก verify API) ต้องอยู่ภายใต้ HTTPS เท่านั้น
- `redirect_uri` ที่ลงทะเบียนไว้ต้องตรงกับ URL จริงเป๊ะ ไม่ใช้ wildcard (ป้องกัน Open Redirect)
- ตัวอย่างโค้ดในคู่มือปิด `CURLOPT_SSL_VERIFYPEER` เป็น `false` (สำหรับแก้ปัญหา cert บน localhost) — **บน production ต้องเปิดกลับเป็น `true`** เพื่อป้องกัน MITM ระหว่าง BPM server กับ MEDSCI ACC
- **Login CSRF**: คู่มือกำหนดให้ระบบย่อย**ต้อง**ส่ง `state` (random nonce เก็บใน session) ไปพร้อม login request และตรวจสอบด้วย `hash_equals()` ตอน callback ก่อนทำสิ่งอื่นใด — ห้ามข้ามขั้นตอนนี้เด็ดขาดแม้จะดูเหมือนไม่จำเป็น (BPM ต้อง implement ตามข้อ 3.3 เป๊ะๆ ไม่ใช่ทางเลือก)
- **อายุ token**: ตามคู่มือ token มีอายุ **120 วินาที (2 นาที)** และเป็นแบบ **single-use** (verify สำเร็จได้ครั้งเดียว เรียกซ้ำโดนปฏิเสธทันที) — ดังนั้นไม่ควรมี logic ใดๆ ที่ verify token ซ้ำ (เช่น retry อัตโนมัติเมื่อ network error) เพราะจะทำให้ verify ครั้งที่สองล้มเหลวเสมอแม้ token ยังไม่หมดอายุจริง
- **Token รั่วผ่าน log**: token เดินทางผ่าน URL query string จึงมีโอกาสหลุดผ่าน `Referer` header, ประวัติ browser, หรือ access log ของ IIS/PHP — อย่า log full request URL ของ `sso_callback.php` ไว้ในระบบ log ของ BPM เอง (ตัด query string ออกก่อน log ถ้าจำเป็นต้อง log) — ความเสี่ยงถูกจำกัดด้วย token อายุสั้น 2 นาทีอยู่แล้ว แต่ก็ยังควรหลีกเลี่ยง

### 3.7 Logout

MEDSCI ACC มี **Single Logout (SLO) Endpoint** ให้เรียกจริง (อัปเดตจากคู่มือ SSO ล่าสุด):

| รายละเอียด | ค่า |
|---|---|
| Endpoint URL | `https://www.medsci.up.ac.th/msc_acc/sso/logout.php` |
| HTTP Method | `GET` (เป็น browser redirect ธรรมดา ไม่ใช่ server-to-server call แบบ verify API) |
| Parameter | `redirect_uri` (แนะนำ — URL ที่จะให้ระบบกลาง redirect ผู้ใช้กลับมาหลังเคลียร์ session กลางเสร็จ เช่น `login.php`), `client_id` (ไม่บังคับ — ใส่เพื่อให้ MEDSCI ACC บันทึก audit log ฝั่งเขา) |

`public/logout.php` ทำตามลำดับนี้เป๊ะๆ (ต่างจาก verify token flow เพราะเป็น full-page redirect ไม่ใช่ cURL):

1. `session_destroy()` เคลียร์ session ฝั่ง BPM ก่อนเสมอ
2. `header("Location: https://www.medsci.up.ac.th/msc_acc/sso/logout.php?redirect_uri=" . urlencode(<login.php ของ BPM>) . "&client_id=" . urlencode($client_id))` — ส่งต่อไปเคลียร์ session ฝั่งระบบกลางด้วย แล้วให้ MEDSCI ACC redirect ผู้ใช้กลับมาที่ `login.php` เองหลังเคลียร์เสร็จ

ผลคือผู้ใช้ที่ logout แล้ว กด login ใหม่จะต้องกรอกบัญชี UP Account ใหม่จริง ไม่ auto-login ซ้ำ — เหมาะกับเครื่องคอมพิวเตอร์ที่ใช้ร่วมกันหลายคน (shared computer) ในสำนักงาน ไม่มี fallback กรณี endpoint นี้เข้าไม่ได้ต้องกังวล เพราะเป็น redirect ฝั่ง browser ไม่ใช่ server call ที่ต้องจัดการ error/timeout เอง (ถ้า MEDSCI ACC ฝั่งนั้นล่ม ผู้ใช้จะเห็น error หน้าเว็บของ MEDSCI ACC เอง ซึ่งเกินการควบคุมของ BPM — session ฝั่ง BPM ถูกเคลียร์ไปแล้วในขั้นตอนที่ 1 อยู่ดี ไม่กระทบความปลอดภัยของ BPM)

### 3.8 หน้า Error กลางสำหรับ SSO (`public/error.php`)

โค้ดตัวอย่างในคู่มือ SSO ใช้ `die("ข้อความ")` ตรงๆ ซึ่งเหมาะกับ debug เท่านั้น **ห้ามก็อปเข้า production ตรงๆ** เพราะขึ้นเป็นหน้าขาวข้อความดิบ ขัดกับ [BPM_UI_UX_GUIDELINES.md](BPM_UI_UX_GUIDELINES.md) — ให้ `sso_callback.php` ทำ `header("Location: error.php?type=...")` แทนทุกจุดที่คู่มือใช้ `die()`/`echo`, และให้ `error.php` render หน้า error ที่ใช้ layout เดียวกับระบบ (ไม่ต้อง login) พร้อมปุ่ม "ลองเข้าสู่ระบบใหม่" กลับไปที่ `login.php` แยกประเภท error อย่างน้อย:

| `type` | สาเหตุ | ข้อความที่ควรสื่อสาร |
|---|---|---|
| `state_invalid` | state ไม่ตรง/หาย (ข้อ 3.6) | "คำขอเข้าสู่ระบบไม่ถูกต้องหรือหมดเวลา กรุณาเข้าสู่ระบบใหม่อีกครั้ง" |
| `token_missing` | ไม่มี `token` ส่งมา | เหมือนด้านบน |
| `token_expired` | verify คืน status=failed (token หมดอายุ/ใช้ซ้ำ — คู่มือระบุอายุ 120 วินาที) | "เซสชันเข้าสู่ระบบหมดอายุ (ใช้เวลานานเกินไป) กรุณาลองใหม่อีกครั้ง" |
| `verify_unreachable` | `curl_errno()` ไม่เป็นศูนย์ (เชื่อมต่อ MEDSCI ACC ไม่ได้) | "ไม่สามารถเชื่อมต่อระบบยืนยันตัวตนกลางได้ในขณะนี้ กรุณาลองใหม่ภายหลัง หรือแจ้งผู้ดูแลระบบ" — แยกจาก error ด้านบนเพราะเป็นปัญหาเครือข่าย ไม่ใช่ปัญหาที่ผู้ใช้แก้เองได้ด้วยการลองใหม่ทันที |
| `not_authorized` | verify สำเร็จแต่ไม่ใช่บุคลากรคณะ (`status=failed` กรณีอื่น) | ใช้ `$result['message']` จาก API ตรงๆ (ผ่าน `htmlspecialchars()`) |

ทุก error ต้อง log รายละเอียดจริงไว้ฝั่ง server (error log) แต่ข้อความที่ผู้ใช้เห็นต้องเป็นภาษาที่เข้าใจง่ายเสมอ ไม่ใช่ raw exception/stack trace

---

## 4. Technology Stack

| ส่วน | เทคโนโลยี | หมายเหตุ |
|---|---|---|
| ภาษา/Runtime | **PHP 8.2+** | ใช้ PDO (`PDO::ATTR_EMULATE_PREPARES = false`) เชื่อมต่อ MariaDB เท่านั้น ห้ามต่อ DB ด้วย `mysqli`/query string ตรงๆ เพื่อกัน SQL Injection, ต้องเปิด extension `curl` (`extension=curl` ใน `php.ini`) สำหรับเรียก MEDSCI ACC API, ตั้ง `date_default_timezone_set('Asia/Bangkok')` ใน bootstrap ไฟล์กลางที่ทุกหน้าเรียก (ไม่ใช่ตั้งกระจายในแต่ละไฟล์) เพราะถ้า server ตั้ง timezone เป็น UTC จะกระทบการเทียบ `txn_date`/ไตรมาส/ปีงบทั้งระบบ |
| ฐานข้อมูล | **MariaDB 10.6+** | charset/collation ทั้งฐานข้อมูลและทุกตาราง: `utf8mb4` / `utf8mb4_unicode_ci` (จำเป็นสำหรับภาษาไทย), engine `InnoDB` ทุกตาราง (ต้องการ foreign key + transaction) |
| Web server | **IIS** (Windows) | PHP รันผ่าน **FastCGI** (ตั้งค่าใน IIS Manager ด้วย PHP Manager for IIS หรือ manual FastCGI handler mapping) ไม่ใช้ iisnode (นั่นสำหรับ Node.js เท่านั้น) |
| Session/Auth | **MEDSCI ACC SSO** (SSO Redirect) ยืนยันตัวตน + PHP native session (`session_start()`) เก็บ session หลังยืนยันสำเร็จ | ไม่มีการเก็บรหัสผ่านผู้ใช้ในฐานข้อมูล BPM เลย (ดูรายละเอียดเต็มในข้อ 3) |
| Frontend | Server-rendered PHP (template แบบ partials/includes) + CSS ล้วน (ไม่ใช้ build step) + JavaScript vanilla หรือ Alpine.js (CDN) สำหรับ interactivity เล็กๆ (real-time คำนวณยอดคงเหลือในฟอร์ม, filter ตาราง) | หลีกเลี่ยง Node build toolchain เพื่อให้ deploy บน IIS ง่าย ไม่ต้อง build step |
| กราฟ | Chart.js (CDN) | ใช้กราฟแท่งเปรียบเทียบหมวดงบ, สรุปยอดตามไตรมาส ตาม UI guideline |
| Export | PhpSpreadsheet (Composer) สำหรับ Excel, Dompdf (Composer) สำหรับ PDF | ทั้งคู่เป็น pure-PHP library ไม่ต้องพึ่ง binary ภายนอก เหมาะกับ IIS — ต้องเปิด ext-gd และ ext-zip ด้วย (ดูข้อ 11) — **ฟอนต์ default ของ Dompdf ไม่รองรับภาษาไทย ต้อง embed font ไทยเอง** ผ่าน `FontMetrics::registerFont()` (ใช้ Noto Sans Thai ไฟล์เดียว จาก Google Fonts repo ก็พอสำหรับ v1 — เก็บไว้ที่ `src/fonts/NotoSansThai-Regular.ttf`) ไม่งั้น PDF ที่ export จะขึ้นเป็นกล่องสี่เหลี่ยมแทนตัวอักษรไทยทั้งหมด — **จุดพลาดที่เจอจริงตอน dev**: `registerFont()` คืนค่า `false` เงียบๆ (fallback ไป Helvetica โดยไม่มี error ให้เห็น) ถ้าไม่ตั้ง `Options::setChroot()` ให้ครอบคลุมโฟลเดอร์ font — Dompdf sandbox การอ่านไฟล์ local ไว้โดย default แม้จะเป็นไฟล์ในโปรเจกต์เราเอง ต้อง `$options->set('isRemoteEnabled', true)` และ `$options->setChroot([realpath(__DIR__.'/../fonts')])` (จำกัด chroot แคบที่สุดเท่าที่ทำได้ ไม่เปิดกว้างทั้งระบบ) ก่อนเรียก `registerFont()` เสมอ |
| Dependency management | Composer | ต้อง `composer install` บนเครื่อง production (หรือ vendor/ commit ไปพร้อม deploy ถ้าเครื่อง production ไม่มี internet) |
| ฟอนต์ | `Prompt` หรือ `Noto Sans Thai` (Google Fonts self-host หรือ CDN) | ตามคู่มือ UI/UX ข้อ 1 |

**เหตุผลเลือก plain PHP แทน framework ใหญ่ (Laravel ฯลฯ):** ลดความซับซ้อนของการ deploy บน IIS (ไม่ต้องตั้งค่า URL rewrite ซับซ้อน, ไม่ต้อง queue/cron worker), ทีมงานดูแลง่าย ถ้าในอนาคตทีมพัฒนาถนัด framework ใดเป็นพิเศษและยอมรับความซับซ้อนของการ deploy เพิ่มขึ้น สามารถเปลี่ยนได้โดยไม่กระทบ data model

---

## 5. โครงสร้างฐานข้อมูล (MariaDB Schema)

หลักการ: ใช้ `INT UNSIGNED AUTO_INCREMENT` เป็น primary key (แทน cuid string ของตัวต้นแบบเดิม เพื่อความเร็วและง่ายต่อการ join ใน MySQL/MariaDB), เงินทุกช่องเป็น `DECIMAL(14,2)` ห้ามใช้ FLOAT/DOUBLE

```sql
CREATE DATABASE bpm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bpm;

-- ผู้ใช้งาน (ยืนยันตัวตนผ่าน MEDSCI ACC SSO — ไม่มีการเก็บรหัสผ่านในตารางนี้)
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sso_username  VARCHAR(100)    NOT NULL UNIQUE, -- username บัญชี UP Account เช่น wittaya.su
  sso_user_id   VARCHAR(50)     NULL,             -- user_id ที่ MEDSCI ACC ส่งกลับมา (เก็บไว้ debug/audit)
  name          VARCHAR(150)    NOT NULL,
  email         VARCHAR(191)    NOT NULL,
  pos_name      VARCHAR(150)    NULL,             -- ตำแหน่ง จาก SSO (informational)
  div_name      VARCHAR(150)    NULL,             -- สังกัด จาก SSO (informational)
  role          ENUM('ADMIN','DEPT_STAFF','EXECUTIVE_VIEWER') NULL, -- NULL = ยังไม่ถูกกำหนดสิทธิ์ ห้ามใช้งานฟีเจอร์ใดๆ
  department_id INT UNSIGNED    NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  last_login_at DATETIME        NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- สาขาวิชา
CREATE TABLE departments (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(150) NOT NULL,
  code      VARCHAR(30)  NOT NULL UNIQUE,
  is_active TINYINT(1)   NOT NULL DEFAULT 1 -- ปิดการใช้งานแทนการลบจริง (ดูเหตุผลในข้อ 5.3)
) ENGINE=InnoDB;

-- ปีงบประมาณ (ราชการไทย: 1 ต.ค. ปีก่อนหน้า - 30 ก.ย. ปีปัจจุบัน, แสดงเป็น พ.ศ.)
CREATE TABLE fiscal_years (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_be    SMALLINT UNSIGNED NOT NULL UNIQUE, -- เช่น 2569
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  status     ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN' -- CLOSED = ปิดปีงบแล้ว ห้ามบันทึก/แก้ไขรายการใดๆ อีก (ดูข้อ 6.5)
) ENGINE=InnoDB;

-- กลุ่มหมวดงบ (ใช้แค่จัดกลุ่มเพื่อสรุป/กราฟภาพรวมบน dashboard เท่านั้น — ไม่ใช่ตัวกำหนดการจัดสรรอีกต่อไป ดูเหตุผลในข้อ 6.3)
CREATE TABLE budget_groups (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(100) NOT NULL, -- เช่น ค่าตอบแทน, ค่าใช้สอย, ค่าวัสดุ, ค่าครุภัณฑ์, โครงการ, อื่นๆ
  code      VARCHAR(30)  NOT NULL UNIQUE,
  is_active TINYINT(1)   NOT NULL DEFAULT 1 -- ปิดการใช้งานแทนการลบจริง (ดูเหตุผลในข้อ 5.3)
) ENGINE=InnoDB;

-- รายการงบประมาณจริง (ตรงกับคอลัมน์ "รายการ" ในไฟล์ Excel ของฝ่ายการเงิน) — ตั้งขึ้นใหม่ทุกปีงบ แยกต่อสาขา
-- ไม่ใช่ taxonomy ที่ใช้ร่วมกันทุกสาขา เพราะแต่ละสาขามีรายการของตัวเอง (ยืนยันกับลูกค้าแล้วว่าต้องละเอียดระดับนี้)
CREATE TABLE budget_line_items (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id          INT UNSIGNED NOT NULL,
  fiscal_year_id         INT UNSIGNED NOT NULL,
  group_id               INT UNSIGNED NULL,          -- กลุ่มหมวดงบ (optional, ใช้ทำกราฟสรุปภาพรวมเท่านั้น)
  name                   VARCHAR(255) NOT NULL,       -- "รายการ" เช่น 'ค่าเบี้ยเลี้ยง ค่าที่พัก และค่าพาหนะ', 'โครงการสหกิจศึกษา หลักสูตรจุลชีววิทยา'
  starting_amount        DECIMAL(14,2) NOT NULL DEFAULT 0, -- งบต้นปี (จัดสรรตั้งต้น = คอลัมน์ "งบต้นปี" ในไฟล์จริง)
  requires_travel_detail TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = ต้องกรอกรายละเอียดผู้เดินทางทุกครั้งที่บันทึกรายจ่าย (ดูข้อ 6.6 และตาราง travel_records)
  note                   VARCHAR(1000) NULL,          -- หมายเหตุอิสระต่อรายการ (เช่น รายละเอียดครุภัณฑ์ที่จะซื้อ)
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_line_item (department_id, fiscal_year_id, name),
  CONSTRAINT fk_li_department FOREIGN KEY (department_id)  REFERENCES departments(id),
  CONSTRAINT fk_li_fiscalyear FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_li_group      FOREIGN KEY (group_id)       REFERENCES budget_groups(id)
) ENGINE=InnoDB;

-- รายการเบิกจ่าย/รายรับ ผูกกับ line item หนึ่งรายการ
CREATE TABLE transactions (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  line_item_id  INT UNSIGNED NOT NULL,
  type          ENUM('EXPENSE','INCOME') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  description   VARCHAR(500) NOT NULL,
  reference_no  VARCHAR(100) NULL,
  txn_date      DATE NOT NULL,
  created_by    INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_txn_lineitem  FOREIGN KEY (line_item_id) REFERENCES budget_line_items(id),
  CONSTRAINT fk_txn_createdby FOREIGN KEY (created_by)   REFERENCES users(id)
) ENGINE=InnoDB;

-- รายละเอียดเสริมสำหรับรายจ่ายประเภท "ค่าเดินทาง/พัฒนาตนเอง" — ผูก 1:1 กับ transactions หนึ่งแถวเสมอ
-- (บันทึกพร้อมกันใน DB transaction เดียวกับการ insert transactions — ไม่ใช่ตารางแยกอิสระที่คำนวณยอดคงเหลือเอง)
CREATE TABLE travel_records (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id  INT UNSIGNED NOT NULL UNIQUE,
  instructor_name VARCHAR(150)  NOT NULL, -- ชื่อผู้เดินทาง/อาจารย์ (free text — ไม่ผูกกับ users เพราะผู้เดินทางอาจไม่มีบัญชี BPM)
  purpose         VARCHAR(1000) NOT NULL, -- รายละเอียดการเดินทาง/ประชุม/อบรม
  installment_no  TINYINT UNSIGNED NOT NULL DEFAULT 1, -- งวดที่เท่าไหร่ของทริปนี้ (1 ทริปจ่ายได้หลายงวด ตามไฟล์จริง)
  ref_doc_no      VARCHAR(150)  NULL,     -- เลขที่เอกสารอ้างอิง (บางครั้งมีหลายเลข เก็บเป็น text ตามที่เอกสารจริงระบุ)
  note            VARCHAR(500)  NULL,
  CONSTRAINT fk_travel_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

-- คำขอโยกย้ายงบระหว่างรายการ (ภายในสาขาและปีงบเดียวกัน — ตรงกับคอลัมน์ "โอนลด"/"โอนเพิ่ม" ในไฟล์จริง)
CREATE TABLE budget_transfers (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id    INT UNSIGNED NOT NULL,
  department_id     INT UNSIGNED NOT NULL,
  from_line_item_id INT UNSIGNED NOT NULL,
  to_line_item_id   INT UNSIGNED NOT NULL,
  amount            DECIMAL(14,2) NOT NULL,
  reason            VARCHAR(500) NOT NULL,
  ref_memo_no       VARCHAR(100) NULL, -- เลขที่บันทึกข้อความอ้างอิง
  status            ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  requested_by      INT UNSIGNED NOT NULL,
  approved_by       INT UNSIGNED NULL,
  decided_at        DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transfer_fiscalyear FOREIGN KEY (fiscal_year_id)    REFERENCES fiscal_years(id),
  CONSTRAINT fk_transfer_department FOREIGN KEY (department_id)     REFERENCES departments(id),
  CONSTRAINT fk_transfer_fromitem   FOREIGN KEY (from_line_item_id) REFERENCES budget_line_items(id),
  CONSTRAINT fk_transfer_toitem     FOREIGN KEY (to_line_item_id)   REFERENCES budget_line_items(id),
  CONSTRAINT fk_transfer_requester  FOREIGN KEY (requested_by)      REFERENCES users(id),
  CONSTRAINT fk_transfer_approver   FOREIGN KEY (approved_by)       REFERENCES users(id)
) ENGINE=InnoDB;

-- ประวัติการเปลี่ยนแปลงข้อมูลสำคัญ (audit trail) — บันทึกแยกจาก created_by/created_at ของแต่ละตาราง
CREATE TABLE audit_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id    INT UNSIGNED NOT NULL,       -- ผู้ทำรายการ
  action      VARCHAR(50)  NOT NULL,       -- เช่น 'ALLOCATION_UPDATE', 'TRANSFER_APPROVE', 'TRANSFER_REJECT', 'USER_ROLE_CHANGE'
  target_table VARCHAR(50) NOT NULL,       -- เช่น 'budget_line_items', 'budget_transfers', 'users'
  target_id   INT UNSIGNED NOT NULL,
  old_value   TEXT NULL,                   -- JSON ของค่าก่อนแก้ (NULL ถ้าเป็นการสร้างใหม่)
  new_value   TEXT NULL,                   -- JSON ของค่าหลังแก้
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_audit_target (target_table, target_id)
) ENGINE=InnoDB;
```

> หมายเหตุลำดับการสร้างตาราง: `departments`, `fiscal_years`, `budget_groups` ต้องถูกสร้างก่อน `users` และ `budget_line_items` เนื่องจากมี FK อ้างถึง (ในไฟล์ migration จริงให้เรียงตามนี้)

### 5.1 การคำนวณยอดคงเหลือ (ต้องคำนวณสดทุกครั้ง ไม่เก็บ cache ในตาราง)

```
ยอดคงเหลือของ line item หนึ่งแถว =
    line_item.starting_amount
  + SUM(budget_transfers.amount WHERE to_line_item = line_item.id AND status = APPROVED)
  - SUM(budget_transfers.amount WHERE from_line_item = line_item.id AND status = APPROVED)
  - SUM(transactions.amount WHERE type = EXPENSE)
  + SUM(transactions.amount WHERE type = INCOME)
```

ตรงกับสูตรที่ฝ่ายการเงินใช้ในไฟล์ Excel จริงพอดี (`งบประมาณรวม = งบต้นปี - โอนลด + โอนเพิ่ม`, `งบคงเหลือ = งบประมาณรวม - รายจ่ายสะสม`) ให้เขียนเป็นฟังก์ชันกลางไฟล์เดียว (เช่น `lib/budget.php`) แล้วเรียกใช้ทุกที่ที่ต้องแสดงยอดคงเหลือ (dashboard, ฟอร์มบันทึกรายการ, ฟอร์มโอนย้ายงบ, รายงาน) — ห้ามคัดลอก query ซ้ำหลายที่ เพื่อป้องกันตัวเลขไม่ตรงกันระหว่างหน้า

รายงานรายเดือน (คอลัมน์ ต.ค.–ก.ย. ในไฟล์จริง) **ไม่ต้องมีคอลัมน์แยกเป็นเดือนใน schema** — คำนวณได้จาก `SUM(transactions.amount) GROUP BY MONTH(txn_date)` ต่อ line item ได้เลย เพราะ `txn_date` เก็บวันที่จริงอยู่แล้ว

### 5.2 Transaction/Concurrency Safety

- การบันทึกรายการเบิกจ่ายและการอนุมัติโอนย้ายงบ ต้องอยู่ใน DB transaction (`beginTransaction()` / `commit()` / `rollBack()`) พร้อม `SELECT ... FOR UPDATE` ล็อคแถว allocation ที่เกี่ยวข้องระหว่างคำนวณยอดคงเหลือ กันกรณีมีผู้ใช้ 2 คนบันทึกพร้อมกันแล้วยอดติดลบโดยไม่รู้ตัว (race condition)
- ก่อน insert/approve ทุกครั้งต้องเช็คยอดคงเหลือ ณ เวลานั้นอีกครั้งฝั่ง server (อย่าเชื่อค่าที่ผู้ใช้เห็นบนฟอร์ม เพราะอาจ stale)
- ทุกครั้งที่มีการแก้ `budget_line_items` (โดยเฉพาะ `starting_amount`), เปลี่ยนสถานะ `budget_transfers`, หรือแก้ `role`/`department_id` ของ `users` ต้อง insert แถวใหม่ลง `audit_logs` (เก็บค่าก่อน/หลังเป็น JSON) ภายใน DB transaction เดียวกับการแก้ข้อมูลจริง เพื่อไม่ให้เกิดกรณีแก้ข้อมูลสำเร็จแต่ audit log หาย
- การบันทึกรายจ่ายที่ `line_item.requires_travel_detail = 1` ต้อง insert `transactions` และ `travel_records` **ในธุรกรรมเดียวกัน** (all-or-nothing) — ถ้า insert `travel_records` ไม่สำเร็จต้อง rollback รายการ `transactions` ทิ้งด้วย ห้ามมี transaction ที่ค้างไม่มีรายละเอียดผู้เดินทางคู่กัน

### 5.3 Validation Rules ระดับ Application

ต้อง validate ฝั่ง server เสมอ (ฝั่ง client ทำเพิ่มได้เพื่อ UX แต่ห้ามพึ่งอย่างเดียว):

- `amount` ทุกช่อง (line item, transaction, transfer) ต้องเป็นตัวเลข **มากกว่า 0** เท่านั้น
- `transactions.txn_date` ต้องอยู่ในช่วง `start_date`–`end_date` ของปีงบที่ line item นั้นสังกัด
- `budget_transfers.from_line_item_id` ต้องไม่เท่ากับ `to_line_item_id` (โอนรายการเดียวกันไม่มีความหมาย) **และทั้งสอง line item ต้องอยู่ department + fiscal_year เดียวกัน** (ห้ามโอนข้ามสาขา/ข้ามปีงบ) — ตรวจสอบฝั่ง server ทุกครั้ง อย่าพึ่งแค่ dropdown filter ฝั่ง UI
- ผู้ใช้ที่มี `role = 'DEPT_STAFF'` ต้องมี `department_id` ไม่เป็น NULL เสมอ (DB อนุญาตให้เป็น NULL ได้ แต่ app ต้องบังคับตอนบันทึกจากหน้า `/admin/users.php`) — ถ้าฝ่าฝืนต้อง reject การบันทึกพร้อม error message ชัดเจน
- ฝั่ง admin เปลี่ยน role เป็น role อื่นที่ไม่ใช่ `DEPT_STAFF` ควรเคลียร์ `department_id` เป็น NULL ให้อัตโนมัติ (ADMIN/EXECUTIVE_VIEWER ไม่ควรผูกสาขา ตามกติกาข้อ 2)
- ห้าม insert/update `transactions` หรือ `budget_transfers` ที่อ้างถึงปีงบที่ `fiscal_years.status = 'CLOSED'` ไม่ว่าจะทางตรง (`fiscal_year_id` ของ transfer) หรือทางอ้อม (ผ่าน `line_item.fiscal_year_id` ของ transaction) — ตรวจสอบ status ของปีงบก่อนทุกครั้งที่บันทึก ไม่ใช่แค่ซ่อนปุ่มบันทึกบน UI (ดูเหตุผลในข้อ 6.5)
- บันทึกรายจ่ายภายใต้ line item ที่ `requires_travel_detail = 1` **ต้องกรอก** `instructor_name` และ `purpose` เสมอ (reject การบันทึกถ้าขาด) — ฟอร์มควรโชว์ field พวกนี้เพิ่มอัตโนมัติเมื่อเลือก line item ประเภทนี้ (ดูข้อ 7)
- หน้า `/admin/departments.php` และหน้าจัดการ `budget_groups` **ห้ามมีปุ่ม "ลบ" แบบ hard delete** ให้มีแค่ปุ่ม "ปิดการใช้งาน" (toggle `is_active = 0`) เท่านั้น เพราะถ้ามี `budget_line_items` ผูกอยู่แล้ว การ `DELETE` จริงจะชน FK constraint แล้วขึ้น DB error ดิบๆ ให้ผู้ใช้เห็น — สาขา/กลุ่มหมวดที่ปิดการใช้งานแล้วต้องไม่ขึ้นเป็นตัวเลือกในฟอร์มสร้างใหม่ แต่ข้อมูลเก่าที่อ้างถึงอยู่แล้วยังต้องแสดงในรายงาน/ประวัติได้ตามปกติ — `budget_line_items` เองก็เช่นกัน (มี `is_active`) ป้องกันกรณีตั้งรายการผิดแล้วมี transaction ผูกแล้วลบไม่ได้

---

## 6. กฎเฉพาะระบบงบประมาณราชการ/สถาบันการศึกษา

(อ้างอิงจาก [BPM_UI_UX_GUIDELINES.md](BPM_UI_UX_GUIDELINES.md) หมวด 5)

1. **รอบปีงบประมาณไทย**: 1 ตุลาคม (ปีก่อนหน้า) – 30 กันยายน (ปีปัจจุบัน) แสดงผลเป็นปี พ.ศ. เสมอ เช่น "ปีงบประมาณ พ.ศ. 2569"
2. **4 ไตรมาส**: Q1 ต.ค.–ธ.ค. / Q2 ม.ค.–มี.ค. / Q3 เม.ย.–มิ.ย. / Q4 ก.ค.–ก.ย.
3. **หมวดงบประมาณ = "รายการ" ระดับเดียวกับที่ฝ่ายการเงินใช้ในไฟล์ Excel จริง** (ยืนยันกับลูกค้าแล้ว — ดูตัวอย่างจริงจาก `BPM_report69.xlsx`/`BPM_70.xlsx`): ไม่ใช่ taxonomy ตายตัว 4 หมวดที่ใช้ร่วมกันทุกสาขาอีกต่อไป แต่ละสาขามีรายการของตัวเอง ตั้งขึ้นใหม่ทุกปีงบ (15-45 รายการต่อสาขา ขึ้นกับปี) เช่น "ค่าเบี้ยเลี้ยง ค่าที่พัก และค่าพาหนะ", "ครุภัณฑ์การศึกษา", "โครงการสหกิจศึกษา หลักสูตรจุลชีววิทยา" — เก็บในตาราง `budget_line_items` (ดูข้อ 5) ส่วนกลุ่มใหญ่แบบเดิม (ค่าตอบแทน/ค่าใช้สอย/ค่าวัสดุ/ค่าครุภัณฑ์) ยังเก็บไว้เป็น `budget_groups` แบบ **optional tag** สำหรับกราฟสรุปภาพรวมบน dashboard เท่านั้น ไม่ใช่ตัวบังคับการจัดสรร
4. **การโอนย้ายงบประมาณ**: ต้องระบุรายการต้นทาง → รายการปลายทาง (ภายในสาขา+ปีงบเดียวกัน), เหตุผล, เลขที่บันทึกข้อความอ้างอิง (optional), ระบบต้องบล็อกอัตโนมัติถ้ายอดที่ขอโอนเกินยอดคงเหลือจริงของรายการต้นทาง ณ เวลาที่ยื่นคำขอ (validate ทั้งตอนยื่นคำขอ และตอนอนุมัติอีกครั้ง เพราะยอดอาจเปลี่ยนไปแล้วระหว่างรออนุมัติ) — ตรงกับคอลัมน์ "โอนลด"/"โอนเพิ่ม" ในไฟล์จริง — การอนุมัติ/ไม่อนุมัติในระบบเป็นขั้นตอนเดียวโดยเจตนา (ดูคำอธิบายในข้อ 1)
5. **ปิดปีงบประมาณ (Fiscal Year Lock)**: เมื่อสิ้นสุดรอบปีงบจริง (30 ก.ย.) ADMIN ต้อง "ปิดปีงบ" ที่หน้า `/admin/fiscal-years.php` (เปลี่ยน `fiscal_years.status` เป็น `CLOSED`) เพื่อล็อกไม่ให้มีใครบันทึก/แก้ไขรายการเบิกจ่ายหรือคำขอโยกย้ายงบย้อนหลังเข้าปีงบนั้นได้อีก (มาตรฐานทั่วไปของระบบบัญชี/งบประมาณ กันการแก้ตัวเลขย้อนหลังโดยไม่มีการควบคุม) — ปีงบที่ปิดแล้วยังคงดูรายงานย้อนหลังได้ตามปกติ เพียงแค่แก้ไข/เพิ่มข้อมูลใหม่ไม่ได้
6. **ค่าเดินทาง/พัฒนาตนเอง เป็น sub-ledger ต่อผู้เดินทาง** (ยืนยันทำเฟสแรกแล้ว — ดูตัวอย่างจริงจากชีท "เดินทางจุล" ฯลฯ): line item ที่ต้อง track แบบนี้ (ปกติคือ "ค่าเบี้ยเลี้ยง ค่าที่พัก และค่าพาหนะ") ตั้ง `requires_travel_detail = 1` แล้วทุกรายจ่ายภายใต้ line item นี้ต้องระบุชื่อผู้เดินทางและรายละเอียดทริปเสมอ (ดูตาราง `travel_records` ในข้อ 5) — 1 ทริปสามารถมีการจ่ายเงินได้หลายงวด (บันทึกเป็นหลาย transaction แยกกัน โดยใช้ `installment_no` ไล่เลข)

---

## 7. ฟีเจอร์/หน้าจอหลัก (Modules)

| หน้า | Route (ตัวอย่าง) | Role ที่เข้าถึงได้ | รายละเอียด |
|---|---|---|---|
| Login | `/login.php` | ทุกคน (ยังไม่ login) | ปุ่มเดียว "เข้าสู่ระบบด้วยบัญชี UP Account" → redirect ไป MEDSCI ACC SSO (ไม่มีฟอร์ม username/password ในระบบนี้) |
| SSO Callback | `/sso_callback.php` | internal (SSO redirect กลับมาเรียก) | รับ token, verify, จับคู่/สร้าง local user, เซ็ต session (ดูข้อ 3) — ผู้ใช้ไม่เห็นหน้านี้โดยตรง |
| รอสิทธิ์การใช้งาน | `/pending-access.php` | ผู้ใช้ที่ login สำเร็จแต่ยังไม่มี role | ข้อความแจ้งให้ติดต่อ ADMIN เพื่อขอกำหนดสิทธิ์ |
| Logout | `/logout.php` | ทุก role ที่ login แล้ว | เคลียร์ session ฝั่ง BPM แล้วเรียก single-logout endpoint ของ MEDSCI ACC ต่อ เพื่อออกจากระบบสมบูรณ์ทั้งสองฝั่ง (ดูข้อ 3.7) |
| Error กลาง (SSO) | `/error.php?type=...` | ทุกคน (ยังไม่ login) | แสดงข้อความ error ที่เข้าใจง่ายตามประเภท (state ผิด/token หมดอายุ/เชื่อมต่อ MEDSCI ACC ไม่ได้ ฯลฯ) พร้อมปุ่มกลับไป login ใหม่ (ดูข้อ 3.8) — แทนที่ `die()` ดิบๆ ในโค้ดตัวอย่างของคู่มือ SSO |
| Dashboard (ภาพรวม) | `/index.php` | ทุก role ที่มีสิทธิ์แล้ว | KPI cards (งบจัดสรรรวม / เบิกจ่ายแล้ว / คงเหลือ / % เบิกจ่าย), กราฟแท่งเปรียบเทียบหมวดงบ, กราฟสรุปยอดตามไตรมาส, ตารางรายการล่าสุด, filter ปีงบ + สาขา (ADMIN/VIEWER เลือกสาขาได้ "ทั้งหมด", DEPT_STAFF ล็อคสาขาตัวเอง) |
| บันทึกเบิกจ่าย/รายรับ | `/transactions.php` | ADMIN, DEPT_STAFF | ตารางรายการ + ฟอร์มเพิ่ม/แก้ไข (เลือก line item จาก dropdown ของสาขา+ปีงบตัวเอง) พร้อมแสดงยอดคงเหลือก่อน/หลังทำรายการแบบ real-time (JS คำนวณจากยอดที่ดึงมา แต่ server ต้องตรวจซ้ำ) — ถ้า line item ที่เลือกมี `requires_travel_detail = 1` ฟอร์มต้องโชว์ field เพิ่ม (ชื่อผู้เดินทาง, รายละเอียดทริป, งวดที่) แล้ว insert `travel_records` คู่กันอัตโนมัติ (ดูข้อ 5.2/5.3) |
| ขอโยกย้ายงบ | `/transfers.php` | ADMIN, DEPT_STAFF (ยื่นคำขอ), ADMIN (อนุมัติ/ไม่อนุมัติ) | ฟอร์มยื่นคำขอ (จาก line item/ไป line item ภายในสาขา+ปีงบเดียวกัน/จำนวน/เหตุผล), ตารางสถานะคำขอ, ปุ่มอนุมัติ/ไม่อนุมัติเฉพาะ ADMIN (ขั้นตอนเดียว เป็นการบันทึกผลเพื่อ monitor — ดูข้อ 1) — เมนู sidebar ของ ADMIN ต้องมี **badge ตัวเลขคำขอที่สถานะ PENDING** ให้เห็นทันทีว่ามีคำขอค้างอนุมัติกี่รายการ โดยไม่ต้องเปิดเข้าหน้านี้ก่อนถึงจะรู้ |
| รายงานสรุป | `/reports.php` | ทุก role (ดูตามสิทธิ์สาขา) | 2 มุมมอง: (1) filter ปีงบ/ไตรมาส/สาขา/line item แบบตารางรายการเดียว (2) **มุมมองตารางไขว้ (matrix)** — แถว = รายการ, คอลัมน์ = สาขา ตรงกับชีท "รวมงบประมาณประจำปี" ที่ฝ่ายการเงินใช้อยู่แล้ว — ปุ่ม Export Excel และ Export PDF ทั้งสองมุมมอง |
| ตั้งค่างบประมาณ | `/admin/allocations.php` | ADMIN | จัดการ `budget_line_items` — เพิ่ม/แก้ "รายการ" ใหม่ต่อสาขา+ปีงบ (ชื่อรายการ, งบต้นปี, กลุ่มหมวด (optional), ติ๊ก "ต้องกรอกรายละเอียดผู้เดินทาง" ถ้าเกี่ยวข้อง, หมายเหตุ) — เพิ่มทีละรายการ หรือ import จากไฟล์ Excel ที่ฝ่ายการเงินส่งมาต้นปี (ดูข้อ 12) |
| ตั้งค่ากลุ่มหมวดงบ | `/admin/budget-groups.php` | ADMIN | CRUD กลุ่มหมวดงบ (optional tag สำหรับกราฟสรุปเท่านั้น — ดูข้อ 6.3) |
| ตั้งค่าสาขาวิชา | `/admin/departments.php` | ADMIN | CRUD สาขาวิชา/หน่วยงาน |
| ตั้งค่าปีงบประมาณ | `/admin/fiscal-years.php` | ADMIN | CRUD ปีงบ (คำนวณ start/end date อัตโนมัติจาก year_be) + ปุ่ม "ปิดปีงบ" เปลี่ยน status เป็น CLOSED (ดูข้อ 6.5) — ต้องมี confirm dialog เพราะ irreversible ในทางปฏิบัติ |
| จัดการผู้ใช้ | `/admin/users.php` | ADMIN | รายชื่อบัญชีที่เคย login ผ่าน SSO ทั้งหมด, กำหนด/แก้ไข role + สาขา, ระงับ (`is_active=0`) การใช้งาน — **ไม่มีฟีเจอร์ตั้ง/reset รหัสผ่าน** เพราะรหัสผ่านอยู่ที่ UP Account เท่านั้น |

---

## 8. UI/UX

ยึดตาม [BPM_UI_UX_GUIDELINES.md](BPM_UI_UX_GUIDELINES.md) ทั้งฉบับ สรุปประเด็นสำคัญที่ต้องคุมทุกหน้า:

- Palette: `--bg-app:#F8FAFC` `--sidebar-bg:#0F172A` `--border-subtle:#E2E8F0` และสี semantic (success/warning/danger) ตามที่กำหนดไว้ — เลี่ยง gradient ม่วง-ชมพู-ฟ้าแบบ AI template
- Flat card ขอบคม 1px border ไม่ใช้ rounded-2xl/3xl + เงาหนา
- ตัวเลขการเงิน **ชิดขวาเสมอ** พร้อม comma และทศนิยม 2 ตำแหน่ง (`number_format($amount, 2)` ใน PHP)
- ฟอนต์ `Prompt` หรือ `Noto Sans Thai` ทั้งระบบ
- Layout: Sidebar ซ้าย (เมนู) + Header บน (filter ปีงบ/สาขา + user) + KPI cards แถวบน + grid กราฟ/ตาราง
- ต้องมี Loading skeleton, Empty state, Toast แจ้งเตือนหลังบันทึกสำเร็จ ตาม checklist ข้อ 6 ของคู่มือ
- **Accessibility ตามความเหมาะสม** (ไม่บังคับ WCAG certification เต็มรูปแบบ แต่ควรทำเป็นพื้นฐาน เพราะเป็นระบบของหน่วยงานรัฐ): contrast ของสีตัวหนังสือ/พื้นหลังอ่านง่าย (palette ที่กำหนดไว้ผ่านเกณฑ์นี้อยู่แล้ว), ทุก input มี `<label>` ผูกกับ field จริง, ปุ่ม/ลิงก์กด tab ได้ตามลำดับและเห็น focus state ชัดเจน, รูปภาพ/ไอคอนสำคัญมี `alt`/`aria-label` — ถ้าลูกค้าระบุภายหลังว่าต้องผ่านมาตรฐานเว็บไซต์ภาครัฐแบบเป็นทางการ ค่อยเพิ่ม audit รอบเต็มทีหลัง

---

## 9. Non-Functional Requirements

- **Security**
  - ทุก query ที่มี input จากผู้ใช้ต้องใช้ PDO prepared statement (bind parameter) ห้าม string concat SQL
  - Escape output ทุกจุดที่พิมพ์ค่าจาก DB ลง HTML ด้วย `htmlspecialchars()` ป้องกัน XSS
  - CSRF token ในทุกฟอร์มที่มีผลต่อข้อมูล (POST)
  - ไม่มีการเก็บรหัสผ่านผู้ใช้ในระบบ BPM เลย (ยืนยันตัวตนผ่าน MEDSCI ACC SSO ทั้งหมด — ดูข้อ 3), `client_secret` ของ SSO เก็บนอก webroot เท่านั้น
  - บังคับ HTTPS บน production ทุกหน้า โดยเฉพาะ `login.php`/`sso_callback.php` (IIS binding + redirect HTTP→HTTPS), ตั้ง cookie session เป็น `Secure` + `HttpOnly` + `SameSite=Lax` — **ห้ามตั้งเป็น `Strict` เด็ดขาด** เพราะ `sso_callback.php` ถูกเรียกด้วย cross-site navigation (302 redirect มาจากโดเมน `www.medsci.up.ac.th`) ถ้าเป็น `Strict` เบราว์เซอร์จะไม่ส่ง session cookie กลับมาด้วย อ่าน `$_SESSION['sso_state']` ที่เก็บไว้ไม่ได้ ทำให้ state validation ล้มเหลวทุกครั้งแม้ผู้ใช้ทำถูกทุกขั้นตอน (ไม่ใช่แค่ best practice ทั่วไป แต่เป็นเงื่อนไขที่ flow SSO นี้ต้องพึ่งพาโดยตรง)
  - จำกัดสิทธิ์ตาม role ทุกจุดฝั่ง server (ไม่ใช่แค่ซ่อนปุ่มฝั่ง UI) รวมถึงเช็คว่า `role IS NOT NULL` ก่อนอนุญาตเข้าฟีเจอร์ใดๆ (กันบัญชี SSO ใหม่ที่ยังไม่ถูกกำหนดสิทธิ์)
  - **ห้าม cache role/department/is_active ไว้ใน `$_SESSION` แล้วเชื่อตลอดอายุ session** — ให้ query ค่าล่าสุดจากตาราง `users` ทุก request ที่มีผลต่อข้อมูล (query เบา มี index บน primary key อยู่แล้ว) เพื่อให้การระงับสิทธิ์/เปลี่ยน role โดย ADMIN มีผลทันที ไม่ต้องรอผู้ใช้ logout/login ใหม่ — สำคัญมากสำหรับระบบการเงินที่อาจต้องตัดสิทธิ์คนออกกะทันหัน
  - **Session idle timeout**: เก็บ `$_SESSION['last_activity'] = time()` แล้วอัปเดตทุก request ที่ login แล้ว ถ้าห่างเกิน threshold (แนะนำ 20-30 นาที) ให้ `session_destroy()` แล้วเด้งกลับไป `login.php` พร้อมข้อความ "เซสชันหมดอายุเนื่องจากไม่มีการใช้งาน" — ห้ามพึ่งแค่ `session.gc_maxlifetime` ของ PHP เพราะเป็นแค่ garbage-collection ไม่ใช่ enforcement ที่รับประกันว่าจะทำงานตรงเวลาเสมอ
  - **Global error handler**: หน้า `error.php` ในข้อ 3.8 ครอบเฉพาะ error จาก SSO flow — ต้องมี exception/error handler กลางอีกชุด (`set_exception_handler` + `set_error_handler` ตั้งใน bootstrap ไฟล์กลาง) ครอบคลุมทั้งระบบ (เช่น DB connection ล่ม, query error) ที่ log รายละเอียดจริงไว้ฝั่ง server แต่โชว์ผู้ใช้เป็นหน้า error ที่เข้าใจง่าย ไม่ใช่ blank page หรือ raw PHP warning/exception
- **Performance**: ตาราง `transactions` และ `budget_line_items` ควรมี index บนคอลัมน์ที่ query บ่อย (`line_item_id`, `department_id`, `fiscal_year_id`, `txn_date`) — ตาราง `transactions` และ `audit_logs` จะมีจำนวนแถวเพิ่มขึ้นเรื่อยๆ ทุกวันไม่มีสิ้นสุด หน้า `/transactions.php` และหน้าดู audit log ต้องทำ pagination (เช่น 50 แถว/หน้า) และควร default filter ช่วงวันที่/ปีงบไว้เสมอ ห้าม query ดึงทั้งตารางมาแสดงในหน้าเดียว — ระวังด้วยว่าจำนวน line item ต่อสาขาต่อปีอยู่ที่ 15-45 รายการ (ไม่ใช่ 4 หมวดเหมือนเดิม) dropdown เลือก line item ในฟอร์มบันทึกรายการควรมีช่องค้นหา/autocomplete ไม่ใช่ dropdown ธรรมดายาวๆ
- **Audit**: เก็บ `created_by`/`created_at` ทุกตารางที่เป็น transactional data (มีอยู่แล้วในสคีมา) เพื่อตรวจสอบย้อนหลังได้ และเก็บการเปลี่ยนแปลงข้อมูลสำคัญ (แก้ยอดจัดสรร, อนุมัติ/ปฏิเสธการโอนย้าย, เปลี่ยน role ผู้ใช้) ลงตาราง `audit_logs` แยกต่างหาก (ดูข้อ 5.2/5) เพื่อให้ตรวจสอบ "ใครแก้อะไร จากค่าเดิมเป็นค่าใหม่" ย้อนหลังได้ครบ ไม่ใช่แค่รู้ว่าใครเป็นคนสร้างแถวล่าสุด
- **Backup**: ตั้ง scheduled task `mysqldump`/`mariadb-dump` รายวันบนเครื่อง production เก็บอย่างน้อย 30 วันย้อนหลัง
- **Responsive**: ใช้งานได้บน tablet/mobile โดยข้อมูลไม่ล้นจอ (ตาราง scroll แนวนอนได้ในกรอบของตัวเอง)
- **External dependency**: ระบบพึ่งพา MEDSCI ACC (`www.medsci.up.ac.th`) เป็น single point of failure ของการ login — ถ้าเซิร์ฟเวอร์กลางล่มหรือเข้าไม่ถึง (firewall/เครือข่าย) ผู้ใช้จะ login เข้า BPM ไม่ได้เลย ควรมีหน้า error ที่สื่อสารชัดเจนเมื่อ cURL เชื่อมต่อ verify API ไม่สำเร็จ (แยกจาก error กรณี token ไม่ถูกต้อง)

---

## 10. โครงสร้างโปรเจกต์ที่แนะนำ

```
/bpm
├── public/                 <- Physical path ของ IIS site ชี้มาที่นี่
│   ├── index.php           (dashboard)
│   ├── login.php           (redirect ไป SSO)
│   ├── sso_callback.php    (รับ token, verify, provisioning)
│   ├── logout.php
│   ├── error.php           (หน้า error กลางของ SSO — ดูข้อ 3.8)
│   ├── pending-access.php  (บัญชียังไม่ถูกกำหนด role)
│   ├── transactions.php
│   ├── transfers.php
│   ├── reports.php
│   ├── admin/
│   │   ├── allocations.php  (จัดการ budget_line_items ต่อสาขา+ปีงบ — ดูข้อ 7)
│   │   ├── departments.php
│   │   ├── budget-groups.php (จัดการ budget_groups — optional tag)
│   │   ├── fiscal-years.php
│   │   └── users.php
│   ├── actions/             (endpoint บางๆ ที่ web เข้าถึงได้จริง — แต่ละไฟล์แค่ 1 บรรทัด `require` ไฟล์ชื่อเดียวกันใน src/actions/
│   │                         ตรรกะจริงทั้งหมดอยู่ src/actions/ นอก webroot — ฟอร์มทุกอันต้อง POST มาที่ /actions/xxx.php ไม่ใช่ /../src/actions/xxx.php ตรงๆ
│   │                         **จุดพลาดที่เจอจริงตอน dev**: ลืมสร้างไฟล์คู่นี้ใน public/actions/ แล้วฟอร์ม POST ไม่เจอ endpoint เลย ได้ 404 เงียบๆ — ต้องสร้างคู่กันเสมอทุกครั้งที่เพิ่ม action ใหม่)
│   │   └── create-transaction.php
│   ├── assets/              (css, js, favicon)
│   └── web.config           (URL rewrite / FastCGI มี handler ระดับ site อยู่แล้ว)
├── src/
│   ├── lib/
│   │   ├── config.php       (โหลด config/config.php แบบ memoized)
│   │   ├── db.php           (PDO connection)
│   │   ├── auth.php         (SSO verify call, local provisioning, role guard, CSRF, flash message — ดูข้อ 3.4)
│   │   ├── budget.php       (คำนวณยอดคงเหลือ/สรุปยอด/pagination — จุดเดียว ห้ามเขียนซ้ำที่อื่น)
│   │   └── fiscal_year.php  (แปลง พ.ศ., คำนวณไตรมาส, resolve ปีงบจาก query string)
│   ├── partials/
│   │   ├── icons.php        (inline SVG icon ทั้งหมดรวมจุดเดียว)
│   │   ├── layout_start.php (เปิด sidebar+header ใช้ร่วมทุกหน้า — ดูข้อ 8)
│   │   └── layout_end.php
│   ├── actions/             (ตรรกะจริงของทุก POST handler: create-transaction.php, create-transfer.php, decide-transfer.php ฯลฯ
│   │                         require bootstrap.php เอง เรียก bpm_require_role() เอง จบด้วย redirect เสมอ (PRG pattern) — ดูข้อ 5.2/5.3)
│   └── bootstrap.php        (ย้ายมาอยู่ src/ ตรงๆ ไม่ใช่ src/lib/ — ทุกไฟล์ public/*.php require ไฟล์นี้เป็นบรรทัดแรกสุด)
├── config/
│   └── config.php           (DB credentials + sso_client_id/sso_client_secret — เก็บนอก public/ ห้ามเข้าถึงผ่าน URL ได้)
├── docker/                  (dev environment เท่านั้น — ไม่ deploy บน production ดูข้อ 15)
├── vendor/                  (composer — PhpSpreadsheet, Dompdf)
├── sql/
│   └── schema.sql           (DDL ตามข้อ 5)
└── composer.json
```

จุดสำคัญ: **เฉพาะโฟลเดอร์ `public/` เท่านั้นที่เป็น physical path ของ IIS site** ส่วน `config/`, `src/`, `vendor/` ต้องอยู่นอก webroot เพื่อไม่ให้ใครเข้าถึง `config.php` (ที่มี DB password และ SSO client secret) ผ่าน URL ตรงๆ ได้

---

## 11. แนวทาง Deploy บน IIS

1. ติดตั้ง **PHP 8.2+ (x64, Non Thread Safe ถ้าใช้ FastCGI)** บนเครื่อง IIS server, เปิด IIS role + **CGI** feature, เปิด extension ใน `php.ini`: `pdo_mysql`, `curl` (เรียก SSO verify API), **`gd` และ `zip`** (จำเป็นสำหรับ PhpSpreadsheet ตอน Export Excel — เจอจริงตอน dev ว่า composer install ล้มเหลวถ้าไม่มี ไม่ได้ระบุไว้ในสเปกฉบับแรก) — PHP build มาตรฐานของ Windows มักมี `php_gd.dll`/`php_zip.dll` อยู่แล้วแค่ยังไม่ได้เปิด (uncomment `;extension=` ใน php.ini)
2. ติดตั้ง **PHP Manager for IIS** (ทำให้ตั้งค่า FastCGI handler mapping ง่ายขึ้น) หรือ map handler เองผ่าน `applicationHost.config`
3. ติดตั้ง MariaDB server บนเครื่อง IIS เครื่องเดียวกัน (ยืนยันแล้วว่าอยู่เครื่องเดียวกัน — ไม่ต้องเปิด firewall/port 3306 ข้ามเครื่อง เชื่อมต่อผ่าน `127.0.0.1`/`localhost` ได้เลย)
4. ตรวจสอบว่าเครื่อง IIS server เข้าถึง `https://www.medsci.up.ac.th` ได้ (outbound HTTPS ไม่ถูก firewall บล็อก) — ถ้าไม่ผ่านจะ login เข้า BPM ไม่ได้เลย
5. สร้างฐานข้อมูลและรัน `sql/schema.sql`
6. Deploy โค้ดขึ้นเครื่อง (`git clone` หรือ copy ไฟล์), รัน `composer install --no-dev` เพื่อดึง PhpSpreadsheet/Dompdf
7. สร้าง `config/config.php` (ไม่ commit เข้า git — ใส่ใน `.gitignore`) ใส่ DB host/user/password/name **และ** `sso_client_id`/`sso_client_secret`/`sso_redirect_uri` ที่ได้จากการลงทะเบียนในข้อ 3.2
8. สร้าง IIS Site ใหม่ → Physical path ชี้ที่โฟลเดอร์ `public/` → ผูก Application Pool (.NET CLR = No Managed Code เพราะ PHP ไม่ใช้ .NET pipeline)
9. ให้สิทธิ์ **Read** แก่ `IIS AppPool\<ชื่อ pool>` บนทั้งโปรเจกต์ และ **Write** เฉพาะโฟลเดอร์ที่จำเป็น (เช่น session file path, export temp file ถ้ามี)
10. ตั้งค่า binding + HTTPS cert (จำเป็น — SSO callback ต้องเป็น HTTPS) แล้วแจ้ง URL จริงกลับไปปรับ `redirect_uri` ที่ลงทะเบียนไว้กับ MEDSCI ACC ให้ตรงกัน
11. ทดสอบ: เปิดหน้า login → กด login ด้วย UP Account จริง (หรือบัญชี dev bypass ตามข้อ 3.5) → ตรวจว่า callback ทำงาน จับคู่/สร้าง user ได้ → บันทึกรายการทดสอบ → ตรวจว่า error ไม่หลุดออกมาเป็น PHP stack trace (`display_errors = Off` บน production, log error ลงไฟล์แทนด้วย `log_errors = On`)

**Deploy ครั้งถัดไป**: `git pull` → `composer install --no-dev` (ถ้ามีการเพิ่ม dependency) → รัน SQL migration ใหม่ (ถ้ามีการแก้ schema) → ไม่ต้อง recycle app pool ก็ได้เพราะ PHP ไม่มี long-running process แบบ Node (แต่ถ้าใช้ opcache แนะนำ recycle เพื่อ clear cache)

---

## 12. ข้อมูลตั้งต้น (Seed) สำหรับ dev/test

### 12.1 หน่วยงาน/สาขาวิชา

ยืนยันจากลูกค้าแล้ว (ชื่อหน่วยงาน + รหัสย่อภาษาอังกฤษ) ใส่ลงตาราง `departments`:

| # | name | code |
|---|---|---|
| 1 | สาขาวิชาจุลชีววิทยา | `MICRO` |
| 2 | สาขาวิชาชีวเคมี | `BIOCHEM` |
| 3 | สาขาวิชาโภชนาการและการกำหนดอาหาร | `NUTRITION` |
| 4 | สาขาวิชากายวิภาคศาสตร์ | `ANATOMY` |
| 5 | สาขาวิชาสรีรวิทยา | `PHYSIO` |
| 6 | งานบริหาร คณะวิทยาศาสตร์การแพทย์ | `OFFICE` |

หน่วยงานที่ 6 เป็นหน่วยธุรการ ไม่ใช่สาขาวิชาการ แต่มีงบประมาณของตัวเองจึงอยู่ในตารางเดียวกัน — ใช้ `code` แยกชัดเจน (`OFFICE`) จากสาขาวิชาการ

### 12.2 รายการงบประมาณ (`budget_line_items`) — ใช้ไฟล์จริงที่ลูกค้าส่งมา ไม่ต้องสมมติ

ลูกค้าส่งไฟล์ Excel จริง 2 ไฟล์มาให้แล้ว (`BPM_70.xlsx`, `BPM_report69.xlsx`) ซึ่งมีโครงสร้างตรงกับ `budget_line_items` พอดี — **แนะนำเขียนสคริปต์ import ครั้งเดียว** (PHP + PhpSpreadsheet หรือ Python + openpyxl ก็ได้ เพราะเป็นงาน one-off ไม่ต้องรันซ้ำในระบบจริง) แทนการพิมพ์ SQL insert มือ:

- **`BPM_70.xlsx`** (แผนตั้งต้นปีงบ 2570 — โครงสร้างเรียบง่าย แค่ "รายการ" + ยอดต่อสาขา ยังไม่มีคอลัมน์โอน/รายเดือน เพราะยังไม่เริ่มปีงบ) → import เป็น `budget_line_items.starting_amount` ของปีงบ 2570 ตรงๆ จากชีทต่อสาขา (`สรุปงบประมาณ [สาขา] 70`) หรือจากชีทรวม `รวมงบค่าใช้จ่ายวิทย์แพทย์70` (แถว=รายการ, คอลัมน์=สาขา) — ใช้ชีทไหนก็ได้ยอดตรงกัน เลือกที่ parse ง่ายกว่า
- **`BPM_report69.xlsx`** (รายงานระหว่าง/ปลายปีงบ 2569 — มีคอลัมน์ โอนลด/โอนเพิ่ม/รายเดือน/ค่าเดินทางแยกต่อคน) → **ยืนยันแล้วว่าไม่ import เข้าระบบเป็นข้อมูลจริง** (ระบบเริ่มนับจากปีงบ 2570) ใช้ไฟล์นี้เป็นข้อมูลอ้างอิงตรวจสอบว่า schema/สูตรคำนวณให้ผลตรงกับไฟล์จริงหรือไม่เท่านั้น (เอาไปเทียบยอดตอน dev/UAT)
- ชีท "เดินทาง[สาขา]" ในไฟล์ 69 ใช้เป็นตัวอย่างจริงสำหรับทดสอบฟีเจอร์ `travel_records` (ข้อ 6.6) ได้ทันที

### 12.3 อื่นๆ

- `budget_groups` 4-6 กลุ่มตามข้อ 6.3 (optional tag เท่านั้น ไม่ block การ import ถ้ายังไม่ตัดสินใจ mapping)
- ปีงบประมาณ: seed แค่ 2570 (OPEN) รายการเดียว — ปีที่ระบบเริ่มใช้งานจริง ไม่ต้องสร้างปีงบ 2569 ในระบบเลยเพราะไม่ import ข้อมูลย้อนหลัง
- **ไม่ seed ผู้ใช้ล่วงหน้าด้วย SQL** เพราะบัญชีผูกกับ SSO — แทนที่ด้วยขั้นตอนนี้: login ครั้งแรกด้วยบัญชี dev bypass `admin.dev` / `dev1234` (ข้อ 3.5) เพื่อให้ระบบสร้างแถว `users` อัตโนมัติ (จับคู่เป็น `wittaya.su`) แล้ว update role ของแถวนั้นเป็น `ADMIN` ตรงในฐานข้อมูลด้วยมือ (ครั้งเดียว เพื่อเปิดทางให้ใช้หน้า `/admin/users.php` กำหนดสิทธิ์บัญชีอื่นต่อได้ตามปกติ)

---

## 13. ขั้นตอนถัดไป / สิ่งที่ต้องตัดสินใจเพิ่ม

- [x] ~~ยืนยันชื่อ/จำนวนสาขาวิชาจริงที่จะใช้ตั้งต้นในระบบ~~ — ได้รับแล้ว: 5 สาขาวิชา + 1 หน่วยงานธุรการ (จุลชีววิทยา, ชีวเคมี, โภชนาการและการกำหนดอาหาร, กายวิภาคศาสตร์, สรีรวิทยา, งานบริหารคณะฯ) ดูรายละเอียดในข้อ 12
- [x] ~~ยืนยันว่าใช้ plain PHP ตามที่เสนอ~~ — ยืนยันแล้ว: ใช้ plain PHP ตามที่เสนอ (ไม่ใช้ framework)
- [x] ~~เครื่อง IIS server ปัจจุบันมี PHP ติดตั้งอยู่แล้วหรือไม่~~ — ยืนยันแล้ว: มี **PHP 8.2** ติดตั้งอยู่แล้ว (ตรงกับ requirement ในข้อ 4 พอดี ไม่ต้องอัปเกรด) และมีสิทธิ์ admin
- [x] ~~MariaDB จะอยู่เครื่องเดียวกับ IIS หรือแยกเครื่อง~~ — ยืนยันแล้ว: **อยู่เครื่องเดียวกับ IIS** (ไม่ต้องเปิด firewall/port 3306 ข้ามเครื่อง — ตัดขั้นตอนนั้นออกจากคู่มือ deploy ข้อ 11 ได้)
- [x] ~~ต้องการ multi-fiscal-year comparison ในเฟสแรกหรือไม่~~ — ยืนยันแล้ว: **ไม่ต้อง** ในเวอร์ชันแรก (ดูทีละปีงบพอ) — คงไว้เป็นแนวคิดสำหรับเฟสถัดไป
- [x] ~~โดเมน/URL จริงของ BPM คืออะไร~~ — ได้รับแล้ว: `https://www.medsci.up.ac.th` (โดเมนของคณะฯ) — **ยังต้องยืนยัน path/subpath ที่จะ deploy BPM จริง** (สมมติไว้เป็น `/bpm/` ในข้อ 3.2 — ต้องเช็คกับผู้ดูแล IIS อีกที) แล้วค่อยลงทะเบียน Client ID/Secret กับ MEDSCI ACC ตาม `redirect_uri` ที่ยืนยันแล้ว
- [x] ~~เครื่อง dev/production เข้าถึง MEDSCI ACC ผ่าน HTTPS ได้จริงหรือไม่~~ — ยืนยันแล้ว: เข้าถึงได้จริง
- [x] ~~มี single-logout endpoint กลางให้เรียกหรือไม่~~ — ได้รับรายละเอียดครบแล้ว: `GET https://www.medsci.up.ac.th/msc_acc/sso/logout.php?redirect_uri=...&client_id=...` (ดู [sso_integration_guide.md](sso_integration_guide.md) ข้อ 5 และ spec.md ข้อ 3.7 ที่ปรับตามแล้ว)
- [x] ~~ตัวอย่างแบบฟอร์มรายงานงบประมาณที่ฝ่ายการเงินคณะใช้จริง~~ — ได้รับแล้ว 2 ไฟล์ (`BPM_report69.xlsx`, `BPM_70.xlsx`) เปิดดูแล้วพบว่าโครงสร้างข้อมูลจริงละเอียดกว่าที่ออกแบบไว้เดิมมาก (ดูข้อ 5/6 ที่ปรับ schema ตามแล้ว) ยังต้องออกแบบ Export PDF/Excel ให้หน้าตาตรงกับไฟล์เหล่านี้ในเฟส 3 ของแผนพัฒนา
- [x] ~~`code` ย่อภาษาอังกฤษของแต่ละหน่วยงาน~~ — ได้รับแล้ว: `MICRO`, `BIOCHEM`, `NUTRITION`, `ANATOMY`, `PHYSIO`, `OFFICE` (ดูตารางในข้อ 12.1)
- [x] ~~ยืนยันว่าเจ้าหน้าที่ 1 คนดูแลได้แค่ 1 สาขาพอ~~ — ยืนยันแล้ว: **พอสำหรับเวอร์ชันแรก** (ผู้ใช้/สาขาบอกไว้ว่า "เพิ่มทีหลังได้" — คงไว้เป็นข้อจำกัดที่รู้ตัวของ v1 ไม่ต้องปรับ schema ตอนนี้ ดูข้อ 2)
- [x] ~~ต้อง import ข้อมูลปีงบ 2569 เข้าระบบเป็นข้อมูลย้อนหลังด้วยหรือไม่~~ — ยืนยันแล้ว: **ไม่ import** ระบบเริ่มนับตั้งแต่ปีงบ 2570 เป็นต้นไป `BPM_report69.xlsx` ใช้เป็นข้อมูลอ้างอิงตรวจสอบสูตรคำนวณระหว่าง dev/UAT เท่านั้น (ดูข้อ 12.2)
- [x] ~~การ mapping "รายการ" แต่ละอันเข้ากับ `budget_groups`~~ — ยืนยันแล้ว: **ADMIN เลือกเอง** ตอนสร้าง/แก้ไขรายการงบ (ไม่ทำ auto-rule จากคำขึ้นต้นชื่อ) — ทำงานอยู่แล้วผ่าน dropdown "กลุ่มหมวด" ใน `admin/allocations.php` → บันทึกลง `group_id`

---

## 15. Dev Environment ด้วย Docker (ทดสอบก่อน deploy จริงบน IIS)

ใช้ทดสอบ auth flow/schema ระหว่างพัฒนาโดยไม่ต้องมีเครื่อง IIS จริง — **ไม่ใช่สิ่งที่ deploy บน production** (production ใช้ IIS + FastCGI ตามข้อ 11 เท่านั้น) โครงสร้าง container จำลอง physical path แบบเดียวกับ IIS จริง (ชี้ที่ `public/` เท่านั้น) เพื่อให้พฤติกรรมใกล้เคียงที่สุด

**ไฟล์ที่เกี่ยวข้อง**: `docker-compose.yml` (PHP 8.2 + Apache, MariaDB 10.11), `docker/php.Dockerfile`, `docker/entrypoint.sh` (สร้าง `config/config.php` อัตโนมัติจาก `docker/config.docker.php` ถ้ายังไม่มี — dev only), `docker/config.docker.php` (ค่า DB host เป็น `db` ตามชื่อ service, `redirect_uri` เป็น `http://localhost:8080/sso_callback.php`)

**วิธีใช้**:
```powershell
docker compose up -d --build   # ครั้งแรกจะรัน sql/schema.sql อัตโนมัติผ่าน MariaDB init script
```
เปิด `http://localhost:8080/login.php` — ทดสอบ state/CSRF validation, error branches, role guard ได้ทันทีโดยไม่ต้องมี SSO client จริง (ยืนยันแล้วว่าทำงานถูกต้องทุก branch)

**อัปเดต**: ลงทะเบียน client `BPM` กับ MEDSCI ACC จริงแล้ว (redirect URIs ทั้ง production และ `http://localhost:8080/sso_callback.php` สำหรับ dev) และใส่ `client_secret` จริงลงใน `config/config.php` ของเครื่อง dev แล้ว (ไม่ใช่ `docker/config.docker.php` ที่เป็นแค่ template ไม่มี secret จริง) — ทดสอบ login เต็ม flow ด้วยบัญชี dev bypass (`admin.dev`/`dev1234` ข้อ 3.5) ผ่าน browser บนเครื่อง host ได้แล้ว

**Reset ฐานข้อมูล**: `docker compose down -v` (ลบ volume ทิ้งแล้วเริ่มใหม่ — schema.sql จะรันอีกครั้งตอน `up` รอบถัดไป) ธรรมดา `docker compose down` เฉยๆ **ไม่ล้างข้อมูล**

---

## 14. การเตรียมผู้ใช้งานก่อนใช้งานจริง (Rollout)

ผู้ใช้งานส่วนใหญ่ (เจ้าหน้าที่สาขา/ผู้บริหาร) ไม่ใช่สายเทคนิค ควรเตรียมสิ่งต่อไปนี้ไว้ล่วงหน้าก่อน go-live จริง (ยังไม่ต้องทำตอนนี้ แต่กันลืม):

- คู่มือการใช้งานสั้นๆ (1-2 หน้า ต่อ role) เน้นภาพประกอบมากกว่าข้อความ โดยเฉพาะขั้นตอน "บันทึกเบิกจ่าย" และ "ยื่นคำขอโยกย้ายงบ" ที่เจ้าหน้าที่สาขาต้องทำเองบ่อยที่สุด
- Session สาธิต/ทดลองใช้งานสั้นๆ ให้แต่ละสาขาก่อนเปิดใช้จริง (ใช้ข้อมูลทดสอบ ไม่ใช่ข้อมูลจริง)
- ช่องทางแจ้งปัญหา/ขอความช่วยเหลือหลัง go-live (เช่น ไลน์กลุ่ม หรืออีเมลของ ADMIN) ให้ผู้ใช้งานรู้ล่วงหน้าว่าติดปัญหาแล้วต้องติดต่อใคร
