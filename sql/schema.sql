-- ============================================================================
-- BPM — ระบบบริหารจัดการงบประมาณสาขาวิชา
-- คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา
--
-- ดูรายละเอียด/เหตุผลของทุก design decision ใน spec.md ข้อ 5
-- ลำดับการสร้างตารางในไฟล์นี้เรียงตาม FK dependency แล้ว รันได้จากบนลงล่างตรงๆ
-- ============================================================================

CREATE DATABASE IF NOT EXISTS bpm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bpm;

-- ----------------------------------------------------------------------------
-- สาขาวิชา / หน่วยงาน
-- ----------------------------------------------------------------------------
CREATE TABLE departments (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(150) NOT NULL,
  code      VARCHAR(30)  NOT NULL UNIQUE,
  is_active TINYINT(1)   NOT NULL DEFAULT 1 -- ปิดการใช้งานแทนการลบจริง (ดูเหตุผลในข้อ 5.3)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- ปีงบประมาณ (ราชการไทย: 1 ต.ค. ปีก่อนหน้า - 30 ก.ย. ปีปัจจุบัน, แสดงเป็น พ.ศ.)
-- ----------------------------------------------------------------------------
CREATE TABLE fiscal_years (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_be    SMALLINT UNSIGNED NOT NULL UNIQUE, -- เช่น 2570
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  status     ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN' -- CLOSED = ปิดปีงบแล้ว ห้ามบันทึก/แก้ไขรายการใดๆ อีก (ดูข้อ 6.5)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- กลุ่มหมวดงบ (ใช้แค่จัดกลุ่มเพื่อสรุป/กราฟภาพรวมบน dashboard เท่านั้น — ไม่ใช่ตัวกำหนดการจัดสรร ดูข้อ 6.3)
-- ----------------------------------------------------------------------------
CREATE TABLE budget_groups (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(100) NOT NULL,
  code      VARCHAR(30)  NOT NULL UNIQUE,
  is_active TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- ผู้ใช้งาน (ยืนยันตัวตนผ่าน MEDSCI ACC SSO — ไม่มีการเก็บรหัสผ่านในตารางนี้)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- รายการงบประมาณจริง (ตรงกับคอลัมน์ "รายการ" ในไฟล์ Excel ของฝ่ายการเงิน) — ตั้งขึ้นใหม่ทุกปีงบ แยกต่อสาขา
-- ไม่ใช่ taxonomy ที่ใช้ร่วมกันทุกสาขา เพราะแต่ละสาขามีรายการของตัวเอง
-- ----------------------------------------------------------------------------
CREATE TABLE budget_line_items (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id          INT UNSIGNED NOT NULL,
  fiscal_year_id         INT UNSIGNED NOT NULL,
  group_id               INT UNSIGNED NULL,          -- กลุ่มหมวดงบ (optional, ใช้ทำกราฟสรุปภาพรวมเท่านั้น)
  name                   VARCHAR(255) NOT NULL,       -- "รายการ" เช่น 'ค่าเบี้ยเลี้ยง ค่าที่พัก และค่าพาหนะ'
  starting_amount        DECIMAL(14,2) NOT NULL DEFAULT 0, -- งบต้นปี (จัดสรรตั้งต้น)
  requires_travel_detail TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = ต้องกรอกรายละเอียดผู้เดินทางทุกครั้งที่บันทึกรายจ่าย (ดูข้อ 6.6)
  note                   VARCHAR(1000) NULL,          -- หมายเหตุอิสระต่อรายการ
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_line_item (department_id, fiscal_year_id, name),
  CONSTRAINT fk_li_department FOREIGN KEY (department_id)  REFERENCES departments(id),
  CONSTRAINT fk_li_fiscalyear FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_li_group      FOREIGN KEY (group_id)       REFERENCES budget_groups(id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- รายการเบิกจ่าย/รายรับ ผูกกับ line item หนึ่งรายการ
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- รายละเอียดเสริมสำหรับรายจ่ายประเภท "ค่าเดินทาง/พัฒนาตนเอง" — ผูก 1:1 กับ transactions หนึ่งแถวเสมอ
-- (บันทึกพร้อมกันใน DB transaction เดียวกับการ insert transactions — ไม่ใช่ตารางแยกอิสระที่คำนวณยอดคงเหลือเอง)
-- ----------------------------------------------------------------------------
CREATE TABLE travel_records (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id  INT UNSIGNED NOT NULL UNIQUE,
  instructor_name VARCHAR(150)  NOT NULL, -- ชื่อผู้เดินทาง/อาจารย์ (free text — ไม่ผูกกับ users)
  purpose         VARCHAR(1000) NOT NULL, -- รายละเอียดการเดินทาง/ประชุม/อบรม
  installment_no  TINYINT UNSIGNED NOT NULL DEFAULT 1, -- งวดที่เท่าไหร่ของทริปนี้ (1 ทริปจ่ายได้หลายงวด)
  ref_doc_no      VARCHAR(150)  NULL,
  note            VARCHAR(500)  NULL,
  CONSTRAINT fk_travel_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- คำขอโยกย้ายงบระหว่างรายการ (ภายในสาขาและปีงบเดียวกัน — ตรงกับคอลัมน์ "โอนลด"/"โอนเพิ่ม" ในไฟล์จริง)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- ประวัติการเปลี่ยนแปลงข้อมูลสำคัญ (audit trail) — บันทึกแยกจาก created_by/created_at ของแต่ละตาราง
-- ----------------------------------------------------------------------------
CREATE TABLE audit_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id     INT UNSIGNED NOT NULL,       -- ผู้ทำรายการ
  action       VARCHAR(50)  NOT NULL,       -- เช่น 'LINE_ITEM_UPDATE', 'TRANSFER_APPROVE', 'TRANSFER_REJECT', 'USER_ROLE_CHANGE'
  target_table VARCHAR(50) NOT NULL,        -- เช่น 'budget_line_items', 'budget_transfers', 'users'
  target_id    INT UNSIGNED NOT NULL,
  old_value    TEXT NULL,                   -- JSON ของค่าก่อนแก้ (NULL ถ้าเป็นการสร้างใหม่)
  new_value    TEXT NULL,                   -- JSON ของค่าหลังแก้
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_audit_target (target_table, target_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Index เพิ่มเติมสำหรับ query ที่ใช้บ่อย (ดู spec.md ข้อ 9 — Performance)
-- ----------------------------------------------------------------------------
CREATE INDEX idx_txn_lineitem  ON transactions (line_item_id);
CREATE INDEX idx_txn_date      ON transactions (txn_date);
CREATE INDEX idx_li_department ON budget_line_items (department_id, fiscal_year_id);

-- ============================================================================
-- Seed ข้อมูลตั้งต้น (ดู spec.md ข้อ 12)
-- ============================================================================

-- สาขาวิชา/หน่วยงาน คณะวิทยาศาสตร์การแพทย์ (ยืนยันจากลูกค้าแล้ว)
INSERT INTO departments (name, code) VALUES
  ('สาขาวิชาจุลชีววิทยา', 'MICRO'),
  ('สาขาวิชาชีวเคมี', 'BIOCHEM'),
  ('สาขาวิชาโภชนาการและการกำหนดอาหาร', 'NUTRITION'),
  ('สาขาวิชากายวิภาคศาสตร์', 'ANATOMY'),
  ('สาขาวิชาสรีรวิทยา', 'PHYSIO'),
  ('งานบริหาร คณะวิทยาศาสตร์การแพทย์', 'OFFICE');

-- กลุ่มหมวดงบ (optional tag สำหรับกราฟสรุปเท่านั้น — ดูข้อ 6.3, การ mapping รายการ->กลุ่มยังเป็นคำถามเปิด ดูข้อ 13)
INSERT INTO budget_groups (name, code) VALUES
  ('ค่าตอบแทน', 'COMPENSATION'),
  ('ค่าใช้สอย', 'OPERATING'),
  ('ค่าวัสดุ', 'MATERIALS'),
  ('ค่าครุภัณฑ์', 'EQUIPMENT'),
  ('โครงการ', 'PROJECT'),
  ('อื่นๆ', 'OTHER');

-- ปีงบประมาณ พ.ศ. 2570 = 1 ต.ค. 2569 (ค.ศ. 2026) – 30 ก.ย. 2570 (ค.ศ. 2027)
-- ระบบเริ่มนับตั้งแต่ปีงบนี้เป็นต้นไป ไม่ import ข้อมูลปีงบ 2569 ย้อนหลัง (ยืนยันแล้ว ดูข้อ 13)
INSERT INTO fiscal_years (year_be, start_date, end_date, status) VALUES
  (2570, '2026-10-01', '2027-09-30', 'OPEN');

-- หมายเหตุ: ไม่ seed budget_line_items/users ในไฟล์นี้
--   - budget_line_items ต้อง import จาก BPM_70.xlsx ด้วยสคริปต์แยก (ดูข้อ 12.2) เพราะแต่ละสาขามีรายการของตัวเองไม่เท่ากัน
--   - users ผูกกับ SSO ห้าม seed มือ — สร้างครั้งแรกด้วยการ login ผ่าน dev bypass แล้ว update role เป็น ADMIN ด้วยมือ (ดูข้อ 3.5/12.3)
