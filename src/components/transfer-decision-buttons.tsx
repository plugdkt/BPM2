"use client";

import { useActionState, useEffect } from "react";
import { Loader2, Check, X } from "lucide-react";
import { toast } from "sonner";
import { decideTransfer } from "@/lib/actions/transfers";
import { Button } from "@/components/ui/button";

export function TransferDecisionButtons({ transferId }: { transferId: string }) {
  const [state, formAction, pending] = useActionState(decideTransfer, undefined);

  useEffect(() => {
    if (state?.error) toast.error(state.error);
  }, [state]);

  return (
    <div className="flex gap-2">
      <form action={formAction}>
        <input type="hidden" name="transferId" value={transferId} />
        <input type="hidden" name="decision" value="APPROVED" />
        <Button
          type="submit"
          size="sm"
          disabled={pending}
          className="bg-emerald-600 text-white hover:bg-emerald-600/90"
        >
          {pending ? <Loader2 className="size-3.5 animate-spin" /> : <Check className="size-3.5" />}
          อนุมัติ
        </Button>
      </form>
      <form action={formAction}>
        <input type="hidden" name="transferId" value={transferId} />
        <input type="hidden" name="decision" value="REJECTED" />
        <Button type="submit" size="sm" variant="outline" disabled={pending}>
          {pending ? <Loader2 className="size-3.5 animate-spin" /> : <X className="size-3.5" />}
          ปฏิเสธ
        </Button>
      </form>
    </div>
  );
}
