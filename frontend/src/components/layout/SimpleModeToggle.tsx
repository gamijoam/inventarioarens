import { Zap, Layers } from 'lucide-react';
import { useUiModeStore } from '@/stores/uiMode';
import { cn } from '@/lib/cn';

interface SimpleModeToggleProps {
  className?: string;
  compact?: boolean;
}

export function SimpleModeToggle({ className, compact = false }: SimpleModeToggleProps) {
  const isSimpleMode = useUiModeStore((s) => s.isSimpleMode);
  const toggleSimpleMode = useUiModeStore((s) => s.toggleSimpleMode);

  return (
    <button
      type="button"
      onClick={toggleSimpleMode}
      className={cn(
        'group flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-all shadow-2xs',
        isSimpleMode
          ? 'border-primary/40 bg-primary/10 text-primary hover:bg-primary/20 hover:border-primary/60'
          : 'border-border bg-surface text-text-secondary hover:bg-bg hover:text-text-primary',
        className,
      )}
      title={
        isSimpleMode
          ? 'Modo Fácil activo: Clic para ver el Modo Completo con todas las opciones avanzadas'
          : 'Modo Completo activo: Clic para simplificar la interfaz al Modo Fácil'
      }
      aria-label={isSimpleMode ? 'Desactivar Modo Fácil' : 'Activar Modo Fácil'}
      data-testid="simple-mode-toggle"
    >
      {isSimpleMode ? (
        <>
          <Zap className="size-3.5 fill-primary text-primary" aria-hidden="true" />
          {!compact && <span>Modo Fácil</span>}
          <span className="bg-primary text-primary-foreground rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider">
            ON
          </span>
        </>
      ) : (
        <>
          <Layers className="size-3.5 text-text-muted group-hover:text-text-primary" aria-hidden="true" />
          {!compact && <span>Modo Completo</span>}
          <span className="bg-text-muted/20 text-text-secondary rounded-full px-1.5 py-0.5 text-[9px] font-medium uppercase tracking-wider">
            Full
          </span>
        </>
      )}
    </button>
  );
}
