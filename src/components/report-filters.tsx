"use client";

import { useRouter, usePathname } from "next/navigation";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toSelectItems } from "@/lib/utils";

export function ReportFilters({
  fiscalYears,
  departments,
  canPickDepartment,
  currentFy,
  currentDept,
  currentView,
}: {
  fiscalYears: { id: string; yearBE: number }[];
  departments: { id: string; name: string }[];
  canPickDepartment: boolean;
  currentFy: string;
  currentDept: string;
  currentView: string;
}) {
  const router = useRouter();
  const pathname = usePathname();

  function updateParam(key: string, value: string | null) {
    if (!value) return;
    const params = new URLSearchParams({ fy: currentFy, dept: currentDept, view: currentView });
    params.set(key, value);
    router.push(`${pathname}?${params.toString()}`);
  }

  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="space-y-1">
        <p className="text-xs text-muted-foreground">ปีงบประมาณ</p>
        <Select
          value={currentFy}
          items={toSelectItems(fiscalYears, "yearBE")}
          onValueChange={(v) => updateParam("fy", v)}
        >
          <SelectTrigger className="w-32">
            <SelectValue />
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

      {canPickDepartment && (
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">สาขาวิชา</p>
          <Select
            value={currentDept}
            items={{ all: "ทุกสาขาวิชา", ...toSelectItems(departments) }}
            onValueChange={(v) => updateParam("dept", v)}
          >
            <SelectTrigger className="w-48">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">ทุกสาขาวิชา</SelectItem>
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
        <p className="text-xs text-muted-foreground">มุมมอง</p>
        <Tabs value={currentView} onValueChange={(v) => updateParam("view", v)}>
          <TabsList>
            <TabsTrigger value="month">รายเดือน</TabsTrigger>
            <TabsTrigger value="quarter">รายไตรมาส</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>
    </div>
  );
}
