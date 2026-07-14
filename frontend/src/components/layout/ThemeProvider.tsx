/**
 * ThemeProvider simplificado: solo tema claro.
 * Decision del usuario (2026-07-13): no usar dark mode por ahora.
 *
 * Se mantiene la arquitectura de Context para futuro soporte de multiples
 * temas sin romper la API. Solo hay un tema por el momento.
 */
import { createContext, useEffect, useState, type ReactNode } from 'react';

export type Theme = 'light';

export interface ThemeContextValue {
  theme: Theme;
  setTheme: (theme: Theme) => void;
}

export const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);
ThemeContext.displayName = 'ThemeContext';

interface ThemeProviderProps {
  children: ReactNode;
}

export function ThemeProvider({ children }: ThemeProviderProps) {
  // Solo soportamos 'light' por ahora. Si en el futuro agregamos dark,
  // la logica va aqui (clase .dark en <html>, etc.).
  const [theme] = useState<Theme>('light');

  useEffect(() => {
    // Aseguramos que nunca quede la clase .dark en <html>.
    document.documentElement.classList.remove('dark');
  }, []);

  const setTheme = (_next: Theme) => {
    // No-op hasta que se implemente multi-tema.
  };

  return (
    <ThemeContext.Provider value={{ theme, setTheme }}>{children}</ThemeContext.Provider>
  );
}