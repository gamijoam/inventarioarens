/**
 * ThemeProvider para Repuestos Avilacar: soporte multitema dinámico
 * con persistencia en localStorage y sincronización con atributos DOM.
 */
import { createContext, useEffect, useState, type ReactNode } from 'react';

export type ThemeId = 'avilacar-retro' | 'avilacar-orange' | 'avilacar-red' | 'avilacar-dark' | 'classic';
export type Theme = ThemeId;

export interface ThemeConfig {
  id: ThemeId;
  name: string;
  description: string;
  primaryColor: string;
  accentColor: string;
  bgColor: string;
  isDark?: boolean;
}

export const THEMES: Record<ThemeId, ThemeConfig> = {
  'avilacar-retro': {
    id: 'avilacar-retro',
    name: 'Retro Avilacar (Midnight & Fuego)',
    description: 'Estilo clásico retro: fondo cálido, sidebar medianoche y acentos naranja fuego',
    primaryColor: '#ea580c',
    accentColor: '#0f1e36',
    bgColor: '#f8fafc',
    isDark: false,
  },
  'avilacar-orange': {
    id: 'avilacar-orange',
    name: 'Naranja Fuego (Racing)',
    description: 'Naranja dinámico con rojo acento y blanco puro',
    primaryColor: '#ea580c',
    accentColor: '#dc2626',
    bgColor: '#ffffff',
    isDark: false,
  },
  'avilacar-red': {
    id: 'avilacar-red',
    name: 'Rojo Pasión (Sport)',
    description: 'Rojo automotriz con acento naranja y blanco',
    primaryColor: '#dc2626',
    accentColor: '#ea580c',
    bgColor: '#ffffff',
    isDark: false,
  },
  'avilacar-dark': {
    id: 'avilacar-dark',
    name: 'Dark Pitstop (Nocturno)',
    description: 'Fondo carbón con naranja neón y rojo sport',
    primaryColor: '#f97316',
    accentColor: '#ef4444',
    bgColor: '#0b0f17',
    isDark: true,
  },
  'classic': {
    id: 'classic',
    name: 'Azul Clásico',
    description: 'Paleta original azul índigo',
    primaryColor: '#4d35ff',
    accentColor: '#0ea5e9',
    bgColor: '#fafafa',
    isDark: false,
  },
};

export interface ThemeContextValue {
  theme: ThemeId;
  themeConfig: ThemeConfig;
  setTheme: (theme: ThemeId) => void;
  availableThemes: ThemeConfig[];
}

export const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);
ThemeContext.displayName = 'ThemeContext';

const STORAGE_KEY = 'sdi-theme';

export function applyTheme(id: ThemeId): void {
  if (typeof document === 'undefined') return;
  const root = document.documentElement;
  root.setAttribute('data-theme', id);
  if (THEMES[id]?.isDark) {
    root.classList.add('dark');
  } else {
    root.classList.remove('dark');
  }
}

interface ThemeProviderProps {
  children: ReactNode;
}

export function ThemeProvider({ children }: ThemeProviderProps) {
  const [theme, setThemeState] = useState<ThemeId>(() => {
    if (typeof window !== 'undefined') {
      const saved = localStorage.getItem(STORAGE_KEY) as ThemeId;
      if (saved && THEMES[saved]) return saved;
    }
    return 'avilacar-retro';
  });

  const setTheme = (next: ThemeId) => {
    if (!THEMES[next]) return;
    setThemeState(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // ignore storage errors
    }
    applyTheme(next);
  };

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  return (
    <ThemeContext.Provider
      value={{
        theme,
        themeConfig: THEMES[theme] ?? THEMES['avilacar-retro'],
        setTheme,
        availableThemes: Object.values(THEMES),
      }}
    >
      {children}
    </ThemeContext.Provider>
  );
}