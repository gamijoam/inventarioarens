import { createFileRoute, Link } from '@tanstack/react-router';
import { Box, Layers, Settings, Shield } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { ThemeToggle } from '@/components/layout/ThemeToggle';
import { cn } from '@/lib/cn';

export const Route = createFileRoute('/')({
  component: HomePage,
});

const PHASES = [
  {
    id: '0',
    title: 'Fase 0 — Setup base',
    icon: Settings,
    done: true,
    description: 'Vite + React 18 + TypeScript + Tailwind 4 + Radix UI + TanStack Query/Router.',
  },
  {
    id: '1',
    title: 'Fase 1 — Auth + Inventario',
    icon: Layers,
    done: false,
    description: 'Login multi-tenant + Dashboard ejecutivo + Centro de Inventario completo.',
  },
  {
    id: '2-7',
    title: 'Fase 2 a 7',
    icon: Box,
    done: false,
    description: 'Compras, POS, Caja, Traslados, Clientes, CxC/CxP, Access Control, SaaS Master.',
  },
];

function HomePage() {
  return (
    <div className="mx-auto flex min-h-screen w-full max-w-5xl flex-col gap-8 px-4 py-12">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">INVENTARIOARENS</h1>
          <p className="mt-1 text-sm text-text-secondary">
            Backend Laravel API + nuevo frontend SPA. Esta página es la portada de la Fase 0.
          </p>
        </div>
        <ThemeToggle />
      </header>

      <section className="rounded-lg border border-border bg-surface p-6 shadow-sm">
        <div className="flex items-start gap-3">
          <Shield className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <div>
            <h2 className="font-semibold">Sistema de permisos integrado</h2>
            <p className="mt-1 text-sm text-text-secondary">
              El frontend consume 101 permisos jerárquicos del backend + 6 roles base + overrides por
              usuario + scopes por recurso (sucursales, almacenes, grupos de cliente) + field masking
              automático en costos. Ver{' '}
              <Link
                to="/"
                className="text-primary underline-offset-4 hover:underline"
                aria-label="Ir a la documentación de permisos"
              >
                docs/FRONTEND_PERMISSIONS.md
              </Link>
              .
            </p>
          </div>
        </div>
      </section>

      <section>
        <h2 className="mb-4 text-lg font-semibold">Roadmap</h2>
        <ol className="space-y-3">
          {PHASES.map((phase) => (
            <li
              key={phase.id}
              className={cn(
                'flex items-start gap-4 rounded-lg border bg-surface p-4 shadow-sm transition-colors',
                phase.done ? 'border-success/30' : 'border-border'
              )}
            >
              <div
                className={cn(
                  'mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-md',
                  phase.done ? 'bg-success/10 text-success' : 'bg-bg text-text-muted'
                )}
              >
                <phase.icon className="size-5" aria-hidden="true" />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <h3 className="font-medium">{phase.title}</h3>
                  {phase.done ? (
                    <span className="rounded bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                      En curso
                    </span>
                  ) : (
                    <span className="rounded bg-bg px-2 py-0.5 text-xs font-medium text-text-muted">
                      Pendiente
                    </span>
                  )}
                </div>
                <p className="mt-1 text-sm text-text-secondary">{phase.description}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <footer className="mt-auto flex items-center justify-between border-t border-border pt-6 text-xs text-text-muted">
        <span>INVENTARIOARENS · Fase 0 · 2026-07-13</span>
        <Button variant="link" size="sm" asChild>
          <a
            href="https://app.miinventariofacil.com/api"
            target="_blank"
            rel="noreferrer"
          >
            API backend →
          </a>
        </Button>
      </footer>
    </div>
  );
}