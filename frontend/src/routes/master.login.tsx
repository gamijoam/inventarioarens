import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import type React from 'react';
import { ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';

import { useAuth } from '@/auth/useAuth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { useSessionStore } from '@/stores/session';

export const Route = createFileRoute('/master/login')({
  beforeLoad: () => {
    const { user } = useSessionStore.getState();
    if (user?.is_platform_admin) {
      throw redirect({ to: '/master' });
    }
  },
  component: MasterLoginPage,
});

function MasterLoginPage() {
  const { signInPlatform } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    try {
      await signInPlatform({ email, password, device_name: 'master-portal' });
      toast.success('Sesion SaaS Master iniciada.');
      await navigate({ to: '/master' });
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo iniciar sesion master.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-bg px-6 py-10 text-text">
      <form onSubmit={submit} className="border-border w-full max-w-md rounded border bg-surface p-6 shadow-sm">
        <div className="mb-6 flex items-center gap-3">
          <div className="bg-primary text-primary-foreground flex size-11 items-center justify-center rounded">
            <ShieldCheck className="size-5" />
          </div>
          <div>
            <h1 className="text-2xl font-bold">SaaS Master</h1>
            <p className="text-text-muted text-sm">Acceso global de plataforma</p>
          </div>
        </div>

        <div className="space-y-4">
          <div className="space-y-1">
            <Label htmlFor="master-email">Email</Label>
            <Input id="master-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </div>
          <div className="space-y-1">
            <Label htmlFor="master-password">Contrasena</Label>
            <Input id="master-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </div>
        </div>

        <Button className="mt-6 w-full" loading={loading}>
          Entrar al portal
        </Button>
      </form>
    </main>
  );
}
