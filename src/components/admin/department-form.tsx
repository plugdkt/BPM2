"use client";

import { useActionState, useEffect } from "react";
import { toast } from "sonner";
import { createDepartment } from "@/lib/actions/admin";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function DepartmentForm() {
  const [state, formAction, pending] = useActionState(createDepartment, undefined);

  useEffect(() => {
    if (state?.success) toast.success("เพิ่มสาขาวิชาแล้ว");
    if (state?.error) toast.error(state.error);
  }, [state]);

  return (
    <form action={formAction} className="flex items-end gap-2">
      <div className="space-y-1">
        <Label htmlFor="deptName">ชื่อสาขาวิชา</Label>
        <Input id="deptName" name="name" placeholder="เช่น สาขาวิชาคณิตศาสตร์" required className="w-56" />
      </div>
      <div className="space-y-1">
        <Label htmlFor="deptCode">รหัส</Label>
        <Input id="deptCode" name="code" placeholder="เช่น MATH" required className="w-32" />
      </div>
      <Button type="submit" disabled={pending}>
        เพิ่มสาขาวิชา
      </Button>
    </form>
  );
}
