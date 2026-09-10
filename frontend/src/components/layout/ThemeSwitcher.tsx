import { Palette, Check } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/DropdownMenu';
import { useTheme } from './use-theme';
import { cn } from '@/lib/cn';

export function ThemeSwitcher() {
  const { theme, setTheme, availableThemes } = useTheme();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="icon-sm"
          title="Cambiar tema visual"
          aria-label="Cambiar tema visual"
          className="relative text-text-muted hover:text-text-primary"
          data-testid="theme-switcher-trigger"
        >
          <Palette className="size-4" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel className="flex items-center gap-2">
          <Palette className="size-3.5 text-primary" aria-hidden="true" />
          <span>Tema Visual · Repuestos Avilacar</span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        {availableThemes.map((t) => {
          const isSelected = theme === t.id;
          return (
            <DropdownMenuItem
              key={t.id}
              onClick={() => setTheme(t.id)}
              className={cn(
                'flex items-center justify-between gap-3 py-2 cursor-pointer',
                isSelected && 'bg-primary/10 font-medium text-primary'
              )}
            >
              <div className="flex items-center gap-2.5 min-w-0">
                {/* Muestra visual de los 3 colores principales */}
                <div className="flex items-center -space-x-1 shrink-0">
                  <span
                    className="size-3.5 rounded-full border border-white/80 shadow-xs"
                    style={{ backgroundColor: t.primaryColor }}
                    title={`Primario: ${t.primaryColor}`}
                  />
                  <span
                    className="size-3.5 rounded-full border border-white/80 shadow-xs"
                    style={{ backgroundColor: t.accentColor }}
                    title={`Acento: ${t.accentColor}`}
                  />
                  <span
                    className="size-3.5 rounded-full border border-black/20 shadow-xs"
                    style={{ backgroundColor: t.bgColor }}
                    title={`Fondo: ${t.bgColor}`}
                  />
                </div>
                <div className="min-w-0">
                  <p className="text-xs font-medium leading-tight truncate">{t.name}</p>
                  <p className="text-[10px] text-text-muted leading-tight truncate">{t.description}</p>
                </div>
              </div>
              {isSelected && <Check className="size-3.5 text-primary shrink-0 ml-1" aria-hidden="true" />}
            </DropdownMenuItem>
          );
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
