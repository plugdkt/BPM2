import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"
import type React from "react"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** แปลงรายการ {id, name} เป็น map สำหรับ Select.Root's `items` prop เพื่อให้ SelectValue แสดง label ที่ถูกต้อง */
export function toSelectItems<T extends { id: string }>(
  list: T[],
  labelKey: keyof T = "name" as keyof T
): Record<string, React.ReactNode> {
  return Object.fromEntries(list.map((item) => [item.id, String(item[labelKey])]))
}
