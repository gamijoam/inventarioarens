import { describe, expect, it } from 'vitest';
import { getKpiGridClasses, getMetricCardSizeClass } from '../dashboardConfig';

describe('dashboard adaptability', () => {
  it('resuelve la cuadrícula responsiva según la cantidad de tarjetas visibles', () => {
    // 1 tarjeta -> columna única completa
    expect(getKpiGridClasses(1)).toContain('grid-cols-1');
    expect(getKpiGridClasses(1)).not.toContain('grid-cols-2');

    // 2 tarjetas -> 2 columnas en pantallas medianas en adelante para llenar la pantalla
    expect(getKpiGridClasses(2)).toContain('md:grid-cols-2');

    // 3 tarjetas -> 3 columnas en pantallas grandes
    expect(getKpiGridClasses(3)).toContain('lg:grid-cols-3');

    // 4 tarjetas -> 4 columnas en pantallas extra grandes
    expect(getKpiGridClasses(4)).toContain('xl:grid-cols-4');

    // 5 y 6 tarjetas -> 5 y 6 columnas respectivamente
    expect(getKpiGridClasses(5)).toContain('xl:grid-cols-5');
    expect(getKpiGridClasses(6)).toContain('xl:grid-cols-6');

    // 7 u 8 tarjetas -> 4 columnas para distribuir equilibradamente en 2 filas
    expect(getKpiGridClasses(7)).toContain('xl:grid-cols-4');
    expect(getKpiGridClasses(8)).toContain('xl:grid-cols-4');
  });

  it('asigna tamaño hero y tipografía más grande cuando hay pocas tarjetas (1 o 2)', () => {
    const heroConfig = getMetricCardSizeClass(2);
    expect(heroConfig.card).toContain('p-6');
    expect(heroConfig.value).toContain('text-4xl');
    expect(heroConfig.iconBox).toContain('size-14');

    const singleConfig = getMetricCardSizeClass(1);
    expect(singleConfig.card).toContain('p-6');
    expect(singleConfig.value).toContain('text-4xl');
  });

  it('asigna tamaño large cuando hay 3 o 4 tarjetas', () => {
    const largeConfig = getMetricCardSizeClass(3);
    expect(largeConfig.card).toContain('p-5');
    expect(largeConfig.value).toContain('text-3xl');
  });

  it('asigna tamaño estándar cuando hay 7 u 8 tarjetas para mantener orden', () => {
    const stdConfig = getMetricCardSizeClass(8);
    expect(stdConfig.card).toContain('p-4');
    expect(stdConfig.value).toContain('text-2xl');
  });
});
