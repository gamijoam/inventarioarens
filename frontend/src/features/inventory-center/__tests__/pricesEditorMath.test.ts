import { describe, it, expect } from 'vitest';
import { calcAmountFromPercent, calcPercentFromAmount } from '../components/PricesEditor';

describe('PricesEditor Math (Cálculo de Precios por Lista)', () => {
  describe('Modo Margen sobre Venta (Fórmula: Precio = Base / (1 - % / 100))', () => {
    it('calcula 100$ cuando el precio de venta base es 50$ y el porcentaje es 50%', () => {
      // Caso exacto solicitado por el usuario:
      // "SI MI PRECIO DE VENTA CALCULADO YA SEA AUTOMATICO O MANUAL ES 50$ EN ESA LISTA DE PRECIO SI COLOCO 50% ESA LISTA DE PRECIO SE CALCULA A 100$"
      const amount = calcAmountFromPercent(50, 50, 'margin');
      expect(amount).toBe(100);
    });

    it('calcula la inversa: 50% cuando el precio de venta base es 50$ y el precio de lista es 100$', () => {
      const percent = calcPercentFromAmount(50, 100, 'margin');
      expect(percent).toBe(50);
    });

    it('calcula 62.50$ con 20% de margen sobre venta para base de 50$', () => {
      // 50 / (1 - 0.20) = 50 / 0.8 = 62.50
      const amount = calcAmountFromPercent(50, 20, 'margin');
      expect(amount).toBe(62.5);
      expect(calcPercentFromAmount(50, 62.5, 'margin')).toBe(20);
    });

    it('retorna null si el margen es >= 100% (imposible matemáticamente en margen comercial)', () => {
      expect(calcAmountFromPercent(50, 100, 'margin')).toBeNull();
      expect(calcAmountFromPercent(50, 120, 'margin')).toBeNull();
    });

    it('retorna null si la base es <= 0', () => {
      expect(calcAmountFromPercent(0, 50, 'margin')).toBeNull();
      expect(calcAmountFromPercent(-10, 50, 'margin')).toBeNull();
    });
  });

  describe('Modo Recargo sobre Base (Fórmula: Precio = Base * (1 + % / 100))', () => {
    it('calcula 75$ cuando la base es 50$ y el porcentaje es 50%', () => {
      // 50 * (1 + 0.5) = 75
      const amount = calcAmountFromPercent(50, 50, 'markup');
      expect(amount).toBe(75);
    });

    it('calcula 100$ cuando la base es 50$ y el porcentaje es 100%', () => {
      // 50 * (1 + 1) = 100
      const amount = calcAmountFromPercent(50, 100, 'markup');
      expect(amount).toBe(100);
    });

    it('calcula la inversa correctamente en modo recargo', () => {
      expect(calcPercentFromAmount(50, 75, 'markup')).toBe(50);
      expect(calcPercentFromAmount(50, 100, 'markup')).toBe(100);
    });
  });
});
