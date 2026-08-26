"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Wallet,
  LayoutDashboard,
  Landmark,
  Receipt,
  ArrowLeftRight,
  FileBarChart2,
  LogOut,
  Menu,
  X,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { logoutAction } from "@/app/(app)/logout-action";
import type { Role } from "@prisma/client";

const LINKS: { href: string; label: string; icon: React.ElementType; roles?: Role[] }[] = [
  { href: "/dashboard", label: "ภาพรวม", icon: LayoutDashboard },
  { href: "/budgets", label: "จัดการงบประมาณ", icon: Landmark, roles: ["ADMIN"] },
  {
    href: "/transactions",
    label: "บันทึกรายการ",
    icon: Receipt,
    roles: ["ADMIN", "DEPT_STAFF"],
  },
  { href: "/transfers", label: "โยกย้ายงบประมาณ", icon: ArrowLeftRight },
  { href: "/reports", label: "รายงาน", icon: FileBarChart2 },
];

const ROLE_LABEL: Record<Role, string> = {
  ADMIN: "ผู้ดูแลระบบ",
  DEPT_STAFF: "เจ้าหน้าที่สาขา",
  EXECUTIVE_VIEWER: "ผู้บริหาร (ดูอย่างเดียว)",
};

function initials(name: string) {
  const parts = name.trim().split(/\s+/);
  return (parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "");
}

function SidebarContent({
  userName,
  role,
  onNavigate,
}: {
  userName: string;
  role: Role;
  onNavigate?: () => void;
}) {
  const pathname = usePathname();
  const visibleLinks = LINKS.filter((l) => !l.roles || l.roles.includes(role));

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-2 px-4 py-4">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm shadow-primary/30">
          <Wallet className="size-4.5" />
        </span>
        <div className="min-w-0 leading-tight">
          <p className="truncate font-semibold tracking-tight">BPM</p>
          <p className="truncate text-xs text-muted-foreground">งบประมาณสาขาวิชา</p>
        </div>
      </div>

      <nav className="flex-1 space-y-1 px-3 py-2">
        {visibleLinks.map((link) => {
          const active = pathname.startsWith(link.href);
          const Icon = link.icon;
          return (
            <Link
              key={link.href}
              href={link.href}
              onClick={onNavigate}
              className={cn(
                "flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors",
                active
                  ? "bg-primary/10 font-medium text-primary"
                  : "text-foreground/75 hover:bg-muted hover:text-foreground"
              )}
            >
              <Icon className={cn("size-4.5 shrink-0", active && "text-primary")} />
              {link.label}
            </Link>
          );
        })}
      </nav>

      <div className="border-t p-3">
        <div className="flex items-center gap-2.5 rounded-lg px-2 py-2">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-semibold text-accent-foreground">
            {initials(userName)}
          </span>
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-medium">{userName}</p>
            <p className="truncate text-xs text-muted-foreground">{ROLE_LABEL[role]}</p>
          </div>
        </div>
        <form action={logoutAction} className="mt-1">
          <button
            type="submit"
            className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-foreground/75 transition-colors hover:bg-muted hover:text-destructive"
          >
            <LogOut className="size-4.5" />
            ออกจากระบบ
          </button>
        </form>
      </div>
    </div>
  );
}

export function AppSidebar({ userName, role }: { userName: string; role: Role }) {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <>
      {/* Desktop sidebar */}
      <aside className="hidden w-64 shrink-0 border-r bg-card md:flex">
        <SidebarContent userName={userName} role={role} />
      </aside>

      {/* Mobile top bar */}
      <header className="sticky top-0 z-40 flex items-center justify-between border-b bg-background/95 px-4 py-3 backdrop-blur md:hidden">
        <div className="flex items-center gap-2">
          <span className="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Wallet className="size-4" />
          </span>
          <span className="font-semibold tracking-tight">BPM</span>
        </div>
        <button
          type="button"
          onClick={() => setMobileOpen(true)}
          className="flex size-9 items-center justify-center rounded-lg text-foreground/70 hover:bg-muted"
          aria-label="เปิดเมนู"
        >
          <Menu className="size-5" />
        </button>
      </header>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden">
          <div
            className="absolute inset-0 bg-black/40"
            onClick={() => setMobileOpen(false)}
          />
          <div className="absolute inset-y-0 left-0 w-72 max-w-[80%] bg-card shadow-xl">
            <button
              type="button"
              onClick={() => setMobileOpen(false)}
              className="absolute right-3 top-3 flex size-8 items-center justify-center rounded-lg text-foreground/70 hover:bg-muted"
              aria-label="ปิดเมนู"
            >
              <X className="size-4.5" />
            </button>
            <SidebarContent userName={userName} role={role} onNavigate={() => setMobileOpen(false)} />
          </div>
        </div>
      )}
    </>
  );
}
