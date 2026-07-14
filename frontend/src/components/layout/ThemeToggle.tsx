import { useTheme } from './use-theme';

/**
 * Indicador discreto del tema activo. Solo se muestra "Tema claro".
 * Reservado para futuro: boton de toggle cuando se agregue dark mode.
 */
export function ThemeIndicator() {
  const { theme } = useTheme();
  return (
    <span className="text-xs text-text-muted" aria-label={`Tema: ${theme}`}>
      Tema: <span className="font-medium text-text-secondary capitalize">{theme}</span>
    </span>
  );
}