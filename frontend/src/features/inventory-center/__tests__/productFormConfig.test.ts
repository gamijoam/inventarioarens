import { describe, it, expect, beforeEach } from 'vitest';
import {
  getStoredProductFormVisibility,
  saveStoredProductFormVisibility,
  resetStoredProductFormVisibility,
  CREATE_PRODUCT_FORM_VISIBILITY,
  PRODUCT_FORM_SECTIONS,
} from '../productFormConfig';

describe('productFormConfig (Configuración y persistencia de visibilidad)', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('define name y base_price como campos obligatorios en las secciones', () => {
    const identification = PRODUCT_FORM_SECTIONS.find((s) => s.id === 'identification');
    const nameField = identification?.fields.find((f) => f.key === 'name');
    expect(nameField?.required).toBe(true);

    const pricing = PRODUCT_FORM_SECTIONS.find((s) => s.id === 'pricing');
    const basePriceField = pricing?.fields.find((f) => f.key === 'base_price');
    expect(basePriceField?.required).toBe(true);
    expect(basePriceField?.description).toContain('Obligatorio para facturar y vender');
  });

  it('retorna CREATE_PRODUCT_FORM_VISIBILITY por defecto', () => {
    const visibility = getStoredProductFormVisibility('tenant-123');
    expect(visibility.name).toBe(true);
    expect(visibility.base_price).toBe(true);
    expect(visibility.sku).toBe(true);
  });

  it('fuerza siempre name y base_price a true aunque en localStorage estén en false', () => {
    window.localStorage.setItem(
      'product_form_visibility_tenant-123',
      JSON.stringify({
        name: false,
        base_price: false,
        barcode: false,
      }),
    );

    const visibility = getStoredProductFormVisibility('tenant-123');
    expect(visibility.name).toBe(true);
    expect(visibility.base_price).toBe(true);
    expect(visibility.barcode).toBe(false);
  });

  it('al guardar en localStorage siempre persiste name y base_price en true', () => {
    saveStoredProductFormVisibility(
      {
        ...CREATE_PRODUCT_FORM_VISIBILITY,
        name: false as unknown as boolean,
        base_price: false as unknown as boolean,
        description: false,
      },
      'tenant-123',
    );

    const raw = window.localStorage.getItem('product_form_visibility_tenant-123');
    expect(raw).toBeTruthy();
    const parsed = JSON.parse(raw!);
    expect(parsed.name).toBe(true);
    expect(parsed.base_price).toBe(true);
    expect(parsed.description).toBe(false);
  });

  it('resetStoredProductFormVisibility limpia la clave del tenant', () => {
    saveStoredProductFormVisibility(CREATE_PRODUCT_FORM_VISIBILITY, 'tenant-123');
    expect(window.localStorage.getItem('product_form_visibility_tenant-123')).toBeTruthy();

    const def = resetStoredProductFormVisibility('tenant-123');
    expect(window.localStorage.getItem('product_form_visibility_tenant-123')).toBeNull();
    expect(def.base_price).toBe(true);
  });
});
