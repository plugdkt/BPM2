import { auth } from "@/auth";
import { NavBar } from "@/components/nav-bar";
import { Toaster } from "@/components/ui/sonner";

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const session = await auth();
  const user = session!.user;

  return (
    <div className="min-h-screen bg-muted/20">
      <NavBar userName={user.name} role={user.role} />
      <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>
      <Toaster />
    </div>
  );
}
