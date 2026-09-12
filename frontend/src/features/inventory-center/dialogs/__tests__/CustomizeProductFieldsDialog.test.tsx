import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CustomizeProductFieldsDialog } from '../CustomizeProductFieldsDialog';
import { CREATE_PRODUCT_FORM_VISIBILITY } from '../../productFormConfig';

describe('<CustomizeProductFieldsDialog>', () => {
  it('renderiza las 5 secciones y el contador de campos visibles', () => {
    render(
      <CustomizeProductFieldsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={CREATE_PRODUCT_FORM_VISIBILITY}
        onChange={() => {}}
        onReset={() => {}}
      />,
    );

    expect(screen.getByText('Personalizar campos del formulario')).toBeInTheDocument();
    expect(screen.getByText('1. Identificación')).toBeInTheDocument();
    expect(screen.getByText('2. Catálogos')).toBeInTheDocument();
    expect(screen.getByText('3. Control de stock')).toBeInTheDocument();
    expect(screen.getByText('4. Precios')).toBeInTheDocument();
    expect(screen.getByText('5. Garantía y Estado')).toBeInTheDocument();
    expect(screen.getByText(/campos visibles/i)).toBeInTheDocument();
  });

  it('mantiene el campo Nombre como obligatorio y deshabilitado para desmarcar', () => {
    render(
      <CustomizeProductFieldsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={CREATE_PRODUCT_FORM_VISIBILITY}
        onChange={() => {}}
        onReset={() => {}}
      />,
    );

    const nameCheckbox = screen.getByTestId('checkbox-field-name');
    expect(nameCheckbox).toBeDisabled();
    expect(screen.getByText('Obligatorio')).toBeInTheDocument();
  });

  it('permite cambiar la casilla de un campo y llama onChange', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
      <CustomizeProductFieldsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={CREATE_PRODUCT_FORM_VISIBILITY}
        onChange={onChange}
        onReset={() => {}}
      />,
    );

    const tagsCheckbox = screen.getByTestId('checkbox-field-tags');
    expect(tagsCheckbox).not.toBeDisabled();
    await user.click(tagsCheckbox);

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        tags: true,
      }),
    );
  });

  it('llama onReset al pulsar Restablecer predeterminados', async () => {
    const user = userEvent.setup();
    const onReset = vi.fn();
    render(
      <CustomizeProductFieldsDialog
        open={true}
        onOpenChange={() => {}}
        visibility={CREATE_PRODUCT_FORM_VISIBILITY}
        onChange={() => {}}
        onReset={onReset}
      />,
    );

    const resetBtn = screen.getByTestId('reset-fields-defaults-btn');
    await user.click(resetBtn);
    expect(onReset).toHaveBeenCalledTimes(1);
  });
});
