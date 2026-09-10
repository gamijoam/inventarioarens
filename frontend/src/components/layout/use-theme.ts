import { useContext } from 'react';
import { ThemeContext, THEMES, type ThemeContextValue } from './ThemeProvider';

const defaultThemeValue: ThemeContextValue = {
  theme: 'avilacar-orange',
  themeConfig: THEMES['avilacar-orange'],
  setTheme: () => {},
  availableThemes: Object.values(THEMES),
};

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext);
  return ctx ?? defaultThemeValue;
}