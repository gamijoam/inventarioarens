import { useTheme } from './use-theme';

export { ThemeSwitcher } from './ThemeSwitcher';

/**
 * Indicador discreto del tema activo.
 */
export function ThemeIndicator() {
  const { theme, themeConfig } = useTheme();
  return (
    <span className="text-xs text-text-muted" aria-label={`Tema: ${theme}`}>
      Tema: <span className="font-medium text-text-secondary">{themeConfig.name}</span>
    </span>
  );
}