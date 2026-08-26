"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { logoutAction } from "@/app/(app)/logout-action";
import type { Role } from "@prisma/client";

const LINKS: { href: string; label: string; roles?: Role[] }[] = [
  { href: "/dashboard", label: "ภาพรวม" },
  { href: "/budgets", label: "จัดการงบประมาณ", roles: ["ADMIN"] },
  { href: "/transactions", label: "บันทึกรายการ", roles: ["ADMIN", "DEPT_STAFF"] },
  { href: "/transfers", label: "โยกย้ายงบประมาณ" },
  { href: "/reports", label: "รายงาน" },
];

export function NavBar({
  userName,
  role,
}: {
  userName: string;
  role: Role;
}) {
  const pathname = usePathname();
  const visibleLinks = LINKS.filter((l) => !l.roles || l.roles.includes(role));

  return (
    <header className="border-b bg-background">
      <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
        <div className="flex items-center gap-6">
          <span className="font-semibold">BPM · งบประมาณสาขาวิชา</span>
          <nav className="flex gap-1">
            {visibleLinks.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className={cn(
                  "rounded-md px-3 py-1.5 text-sm transition-colors hover:bg-muted",
                  pathname.startsWith(link.href) && "bg-muted font-medium"
                )}
              >
                {link.label}
              </Link>
            ))}
          </nav>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-sm text-muted-foreground">{userName}</span>
          <form action={logoutAction}>
            <Button type="submit" variant="outline" size="sm">
              ออกจากระบบ
            </Button>
          </form>
        </div>
      </div>
    </header>
  );
}
