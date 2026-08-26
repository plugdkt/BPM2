"use client";

import { useActionState, useEffect } from "react";
import { toast } from "sonner";
import { createFiscalYear } from "@/lib/actions/admin";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function FiscalYearForm() {
  const [state, formAction, pending] = useActionState(createFiscalYear, undefined);

  useEffect(() => {
    if (state?.success) toast.success("เพิ่มปีงบประมาณแล้ว");
    if (state?.error) toast.error(state.error);
  }, [state]);

  return (
    <form action={formAction} className="flex items-end gap-2">
      <div className="space-y-1">
        <Label htmlFor="yearBE">ปีงบประมาณ (พ.ศ.)</Label>
        <Input id="yearBE" name="yearBE" type="number" placeholder="2569" required className="w-32" />
      </div>
      <Button type="submit" disabled={pending}>
        เพิ่มปีงบ
      </Button>
    </form>
  );
}
