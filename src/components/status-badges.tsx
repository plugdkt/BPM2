import { ArrowDownRight, ArrowUpRight, Clock, CheckCircle2, XCircle } from "lucide-react";
import { cn } from "@/lib/utils";
import type { TransactionType, TransferStatus } from "@prisma/client";

const badgeBase =
  "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium";

export function TransactionTypeBadge({ type }: { type: TransactionType }) {
  const isExpense = type === "EXPENSE";
  return (
    <span
      className={cn(
        badgeBase,
        isExpense
          ? "bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400"
          : "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400"
      )}
    >
      {isExpense ? <ArrowDownRight className="size-3" /> : <ArrowUpRight className="size-3" />}
      {isExpense ? "รายจ่าย" : "รายรับ"}
    </span>
  );
}

const TRANSFER_STATUS: Record<
  TransferStatus,
  { label: string; icon: typeof Clock; className: string }
> = {
  PENDING: {
    label: "รออนุมัติ",
    icon: Clock,
    className: "bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400",
  },
  APPROVED: {
    label: "อนุมัติแล้ว",
    icon: CheckCircle2,
    className: "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400",
  },
  REJECTED: {
    label: "ปฏิเสธ",
    icon: XCircle,
    className: "bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400",
  },
};

export function TransferStatusBadge({ status }: { status: TransferStatus }) {
  const { label, icon: Icon, className } = TRANSFER_STATUS[status];
  return (
    <span className={cn(badgeBase, className)}>
      <Icon className="size-3" />
      {label}
    </span>
  );
}
