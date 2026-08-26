// ปีงบประมาณราชการไทย: 1 ต.ค. (yearBE-1) - 30 ก.ย. (yearBE)
// Q1 = ต.ค.-ธ.ค., Q2 = ม.ค.-มี.ค., Q3 = เม.ย.-มิ.ย., Q4 = ก.ค.-ก.ย.

export function fiscalYearBounds(yearBE: number) {
  const startDate = new Date(Date.UTC(yearBE - 1 - 543, 9, 1)); // ต.ค. ปีก่อนหน้า (ค.ศ.)
  const endDate = new Date(Date.UTC(yearBE - 543, 8, 30, 23, 59, 59)); // 30 ก.ย.
  return { startDate, endDate };
}

export function currentFiscalYearBE(date: Date = new Date()): number {
  const be = date.getUTCFullYear() + 543;
  return date.getUTCMonth() >= 9 ? be + 1 : be; // ต.ค. เป็นต้นไปคือปีงบถัดไป
}

const QUARTER_MONTHS = [
  [9, 10, 11], // Q1: ต.ค.-ธ.ค. (เดือน 0-indexed: 9,10,11)
  [0, 1, 2], // Q2: ม.ค.-มี.ค.
  [3, 4, 5], // Q3: เม.ย.-มิ.ย.
  [6, 7, 8], // Q4: ก.ค.-ก.ย.
];

export function fiscalQuarterOf(date: Date): 1 | 2 | 3 | 4 {
  const month = date.getUTCMonth();
  const idx = QUARTER_MONTHS.findIndex((months) => months.includes(month));
  return (idx + 1) as 1 | 2 | 3 | 4;
}

export function fiscalQuarterBounds(yearBE: number, quarter: 1 | 2 | 3 | 4) {
  const ceYear = yearBE - 543;
  switch (quarter) {
    case 1:
      return {
        startDate: new Date(Date.UTC(ceYear - 1, 9, 1)),
        endDate: new Date(Date.UTC(ceYear - 1, 11, 31, 23, 59, 59)),
      };
    case 2:
      return {
        startDate: new Date(Date.UTC(ceYear, 0, 1)),
        endDate: new Date(Date.UTC(ceYear, 2, 31, 23, 59, 59)),
      };
    case 3:
      return {
        startDate: new Date(Date.UTC(ceYear, 3, 1)),
        endDate: new Date(Date.UTC(ceYear, 5, 30, 23, 59, 59)),
      };
    case 4:
      return {
        startDate: new Date(Date.UTC(ceYear, 6, 1)),
        endDate: new Date(Date.UTC(ceYear, 8, 30, 23, 59, 59)),
      };
  }
}

export const THAI_MONTHS = [
  "มกราคม",
  "กุมภาพันธ์",
  "มีนาคม",
  "เมษายน",
  "พฤษภาคม",
  "มิถุนายน",
  "กรกฎาคม",
  "สิงหาคม",
  "กันยายน",
  "ตุลาคม",
  "พฤศจิกายน",
  "ธันวาคม",
];
