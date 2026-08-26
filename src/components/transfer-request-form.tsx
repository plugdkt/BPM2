"use client";

import { useActionState, useEffect } from "react";
import { toast } from "sonner";
import { requestTransfer } from "@/lib/actions/transfers";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { toSelectItems } from "@/lib/utils";

export function TransferRequestForm({
  departments,
  categories,
  fiscalYears,
  lockDepartmentId,
}: {
  departments: { id: string; name: string }[];
  categories: { id: string; name: string }[];
  fiscalYears: { id: string; yearBE: number }[];
  lockDepartmentId?: string;
}) {
  const [state, formAction, pending] = useActionState(requestTransfer, undefined);

  useEffect(() => {
    if (state?.success) toast.success("ยื่นคำขอโยกย้ายงบแล้ว รออนุมัติ");
    if (state?.error) toast.error(state.error);
  }, [state]);

  return (
    <form action={formAction} className="grid grid-cols-1 gap-3 sm:grid-cols-3">
      {lockDepartmentId ? (
        <input type="hidden" name="departmentId" value={lockDepartmentId} />
      ) : (
        <div className="space-y-1">
          <Label>สาขาวิชา</Label>
          <Select name="departmentId" items={toSelectItems(departments)} required>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="เลือกสาขาวิชา" />
            </SelectTrigger>
            <SelectContent>
              {departments.map((d) => (
                <SelectItem key={d.id} value={d.id}>
                  {d.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      <div className="space-y-1">
        <Label>ปีงบประมาณ</Label>
        <Select name="fiscalYearId" items={toSelectItems(fiscalYears, "yearBE")} required>
          <SelectTrigger className="w-full">
            <SelectValue placeholder="ปีงบ" />
          </SelectTrigger>
          <SelectContent>
            {fiscalYears.map((f) => (
              <SelectItem key={f.id} value={f.id}>
                {f.yearBE}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1">
        <Label htmlFor="amount">จำนวนเงิน (บาท)</Label>
        <Input id="amount" name="amount" type="number" step="0.01" min="0.01" required />
      </div>

      <div className="space-y-1">
        <Label>จากหมวด</Label>
        <Select name="fromCategoryId" items={toSelectItems(categories)} required>
          <SelectTrigger className="w-full">
            <SelectValue placeholder="หมวดต้นทาง" />
          </SelectTrigger>
          <SelectContent>
            {categories.map((c) => (
              <SelectItem key={c.id} value={c.id}>
                {c.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1">
        <Label>ไปหมวด</Label>
        <Select name="toCategoryId" items={toSelectItems(categories)} required>
          <SelectTrigger className="w-full">
            <SelectValue placeholder="หมวดปลายทาง" />
          </SelectTrigger>
          <SelectContent>
            {categories.map((c) => (
              <SelectItem key={c.id} value={c.id}>
                {c.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1 sm:col-span-3">
        <Label htmlFor="reason">เหตุผลการโยกย้าย</Label>
        <Textarea id="reason" name="reason" required rows={2} />
      </div>

      <div className="sm:col-span-3">
        <Button type="submit" disabled={pending}>
          ยื่นคำขอโยกย้ายงบ
        </Button>
      </div>
    </form>
  );
}
