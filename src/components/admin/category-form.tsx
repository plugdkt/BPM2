"use client";

import { useActionState, useEffect } from "react";
import { toast } from "sonner";
import { createBudgetCategory } from "@/lib/actions/admin";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function CategoryForm() {
  const [state, formAction, pending] = useActionState(createBudgetCategory, undefined);

  useEffect(() => {
    if (state?.success) toast.success("เพิ่มหมวดงบประมาณแล้ว");
    if (state?.error) toast.error(state.error);
  }, [state]);

  return (
    <form action={formAction} className="flex items-end gap-2">
      <div className="space-y-1">
        <Label htmlFor="catName">ชื่อหมวดงบ</Label>
        <Input id="catName" name="name" placeholder="เช่น ค่าตอบแทน" required className="w-48" />
      </div>
      <div className="space-y-1">
        <Label htmlFor="catCode">รหัส</Label>
        <Input id="catCode" name="code" placeholder="เช่น COMPENSATION" required className="w-40" />
      </div>
      <Button type="submit" disabled={pending}>
        เพิ่มหมวดงบ
      </Button>
    </form>
  );
}
