"use client";

import { useActionState, useEffect } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { createTransaction } from "@/lib/actions/transactions";
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

export function TransactionForm({
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
  const [state, formAction, pending] = useActionState(createTransaction, undefined);

  useEffect(() => {
    if (state?.success) toast.success("บันทึกรายการแล้ว");
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
        <Label>หมวดงบ</Label>
        <Select name="categoryId" items={toSelectItems(categories)} required>
          <SelectTrigger className="w-full">
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
        <Label>ประเภทรายการ</Label>
        <Select name="type" required defaultValue="EXPENSE">
          <SelectTrigger className="w-full">
            <SelectValue placeholder="ประเภท">
              {(value: string | null) => (value === "INCOME" ? "รายรับ" : "รายจ่าย")}
            </SelectValue>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="EXPENSE">รายจ่าย</SelectItem>
            <SelectItem value="INCOME">รายรับ</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-1">
        <Label htmlFor="amount">จำนวนเงิน (บาท)</Label>
        <Input id="amount" name="amount" type="number" step="0.01" min="0.01" required />
      </div>

      <div className="space-y-1">
        <Label htmlFor="date">วันที่</Label>
        <Input id="date" name="date" type="date" required defaultValue={new Date().toISOString().slice(0, 10)} />
      </div>

      <div className="space-y-1 sm:col-span-2">
        <Label htmlFor="description">รายละเอียด</Label>
        <Textarea id="description" name="description" required rows={1} />
      </div>

      <div className="space-y-1">
        <Label htmlFor="referenceNo">เลขที่เอกสารอ้างอิง</Label>
        <Input id="referenceNo" name="referenceNo" />
      </div>

      <div className="sm:col-span-3">
        <Button type="submit" disabled={pending}>
          {pending && <Loader2 className="size-4 animate-spin" />}
          บันทึกรายการ
        </Button>
      </div>
    </form>
  );
}
