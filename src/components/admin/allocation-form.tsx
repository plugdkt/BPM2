"use client";

import { useActionState, useEffect } from "react";
import { toast } from "sonner";
import { upsertAllocation } from "@/lib/actions/admin";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toSelectItems } from "@/lib/utils";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export function AllocationForm({
  departments,
  categories,
  fiscalYears,
}: {
  departments: { id: string; name: string }[];
  categories: { id: string; name: string }[];
  fiscalYears: { id: string; yearBE: number }[];
}) {
  const [state, formAction, pending] = useActionState(upsertAllocation, undefined);

  useEffect(() => {
    if (state?.success) toast.success("บันทึกการจัดสรรงบแล้ว");
    if (state?.error) toast.error(state.error);
  }, [state]);

  return (
    <form action={formAction} className="flex flex-wrap items-end gap-2">
      <div className="space-y-1">
        <Label>สาขาวิชา</Label>
        <Select name="departmentId" items={toSelectItems(departments)} required>
          <SelectTrigger className="w-48">
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
      <div className="space-y-1">
        <Label>หมวดงบ</Label>
        <Select name="categoryId" items={toSelectItems(categories)} required>
          <SelectTrigger className="w-44">
            <SelectValue placeholder="เลือกหมวดงบ" />
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
        <Label>ปีงบประมาณ</Label>
        <Select name="fiscalYearId" items={toSelectItems(fiscalYears, "yearBE")} required>
          <SelectTrigger className="w-32">
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
        <Input id="amount" name="amount" type="number" step="0.01" min="0" required className="w-40" />
      </div>
      <Button type="submit" disabled={pending}>
        บันทึกการจัดสรร
      </Button>
    </form>
  );
}
