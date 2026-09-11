import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, beforeEach } from 'vitest';
import { ThemeProvider, THEMES } from '../ThemeProvider';
import { ThemeSwitcher } from '../ThemeSwitcher';
import { useTheme } from '../use-theme';

function TestConsumer() {
  const { theme, setTheme, themeConfig } = useTheme();
  return (
    <div>
      <span data-testid="current-theme">{theme}</span>
      <span data-testid="current-name">{themeConfig.name}</span>
      <button data-testid="btn-retro" onClick={() => setTheme('avilacar-retro')}>
        Set Retro
      </button>
      <button data-testid="btn-red" onClick={() => setTheme('avilacar-red')}>
        Set Red
      </button>
      <button data-testid="btn-dark" onClick={() => setTheme('avilacar-dark')}>
        Set Dark
      </button>
      <button data-testid="btn-classic" onClick={() => setTheme('classic')}>
        Set Classic
      </button>
      <button data-testid="btn-orange" onClick={() => setTheme('avilacar-orange')}>
        Set Orange
      </button>
    </div>
  );
}

describe('Theme System for Repuestos Avilacar', () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.classList.remove('dark');
  });

  it('initializes with avilacar-retro by default', () => {
    render(
      <ThemeProvider>
        <TestConsumer />
      </ThemeProvider>
    );

    expect(screen.getByTestId('current-theme').textContent).toBe('avilacar-retro');
    expect(screen.getByTestId('current-name').textContent).toBe(THEMES['avilacar-retro'].name);
    expect(document.documentElement.getAttribute('data-theme')).toBe('avilacar-retro');
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });

  it('switches to avilacar-red and updates DOM attribute and localStorage', () => {
    render(
      <ThemeProvider>
        <TestConsumer />
      </ThemeProvider>
    );

    act(() => {
      fireEvent.click(screen.getByTestId('btn-red'));
    });

    expect(screen.getByTestId('current-theme').textContent).toBe('avilacar-red');
    expect(document.documentElement.getAttribute('data-theme')).toBe('avilacar-red');
    expect(localStorage.getItem('sdi-theme')).toBe('avilacar-red');
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });

  it('switches to avilacar-dark and adds dark class to documentElement', () => {
    render(
      <ThemeProvider>
        <TestConsumer />
      </ThemeProvider>
    );

    act(() => {
      fireEvent.click(screen.getByTestId('btn-dark'));
    });

    expect(screen.getByTestId('current-theme').textContent).toBe('avilacar-dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('avilacar-dark');
    expect(localStorage.getItem('sdi-theme')).toBe('avilacar-dark');
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('switches to classic theme and removes dark class', () => {
    localStorage.setItem('sdi-theme', 'avilacar-dark');

    render(
      <ThemeProvider>
        <TestConsumer />
      </ThemeProvider>
    );

    expect(document.documentElement.classList.contains('dark')).toBe(true);

    act(() => {
      fireEvent.click(screen.getByTestId('btn-classic'));
    });

    expect(screen.getByTestId('current-theme').textContent).toBe('classic');
    expect(document.documentElement.getAttribute('data-theme')).toBe('classic');
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });

  it('renders ThemeSwitcher trigger button and displays options on click', () => {
    render(
      <ThemeProvider>
        <ThemeSwitcher />
      </ThemeProvider>
    );

    const trigger = screen.getByTestId('theme-switcher-trigger');
    expect(trigger).toBeDefined();

    act(() => {
      fireEvent.keyDown(trigger, { key: 'Enter' });
    });

    // Theme names should be rendered in the dropdown
    expect(screen.getByText('Retro Avilacar (Midnight & Fuego)')).toBeDefined();
    expect(screen.getByText('Naranja Fuego (Racing)')).toBeDefined();
    expect(screen.getByText('Rojo Pasión (Sport)')).toBeDefined();
    expect(screen.getByText('Dark Pitstop (Nocturno)')).toBeDefined();
    expect(screen.getByText('Azul Clásico')).toBeDefined();
  });

  it('switches theme when clicking an option in ThemeSwitcher dropdown', () => {
    render(
      <ThemeProvider>
        <ThemeSwitcher />
      </ThemeProvider>
    );

    const trigger = screen.getByTestId('theme-switcher-trigger');
    act(() => {
      fireEvent.keyDown(trigger, { key: 'Enter' });
    });

    const redOption = screen.getByText('Rojo Pasión (Sport)');
    act(() => {
      fireEvent.click(redOption);
    });

    expect(document.documentElement.getAttribute('data-theme')).toBe('avilacar-red');
    expect(localStorage.getItem('sdi-theme')).toBe('avilacar-red');
  });
});
