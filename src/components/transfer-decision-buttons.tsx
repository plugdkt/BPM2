"use client";

import { useActionState, useEffect } from "react";
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
        <Button type="submit" size="sm" disabled={pending}>
          อนุมัติ
        </Button>
      </form>
      <form action={formAction}>
        <input type="hidden" name="transferId" value={transferId} />
        <input type="hidden" name="decision" value="REJECTED" />
        <Button type="submit" size="sm" variant="outline" disabled={pending}>
          ปฏิเสธ
        </Button>
      </form>
    </div>
  );
}
