import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UnlockDashboardPinDialog } from '../dialogs/UnlockDashboardPinDialog';
import { ManageDashboardPinDialog } from '../dialogs/ManageDashboardPinDialog';
import * as security from '../dashboardSecurity';

describe('Dashboard PIN Dialogs', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  describe('UnlockDashboardPinDialog', () => {
    it('muestra error cuando el PIN es incorrecto y llama onSuccess cuando es correcto', async () => {
      await security.setDashboardPin('1234', 1);

      const onSuccess = vi.fn();
      const onOpenChange = vi.fn();

      render(
        <UnlockDashboardPinDialog
          open={true}
          onOpenChange={onOpenChange}
          tenantId={1}
          onSuccess={onSuccess}
        />,
      );

      const input = screen.getByLabelText(/PIN de seguridad/i);
      const submitBtn = screen.getByRole('button', { name: /Desbloquear/i });

      // Probar PIN incorrecto
      fireEvent.change(input, { target: { value: '9999' } });
      fireEvent.click(submitBtn);

      await waitFor(() => {
        expect(screen.getByText(/El PIN introducido es incorrecto/i)).toBeInTheDocument();
      });
      expect(onSuccess).not.toHaveBeenCalled();

      // Probar PIN correcto
      fireEvent.change(input, { target: { value: '1234' } });
      fireEvent.click(submitBtn);

      await waitFor(() => {
        expect(onSuccess).toHaveBeenCalled();
        expect(onOpenChange).toHaveBeenCalledWith(false);
      });
    });
  });

  describe('ManageDashboardPinDialog', () => {
    it('permite configurar un nuevo PIN cuando hasPin es false', async () => {
      const onPinSaved = vi.fn();
      const onOpenChange = vi.fn();

      render(
        <ManageDashboardPinDialog
          open={true}
          onOpenChange={onOpenChange}
          tenantId={2}
          hasPin={false}
          onPinSaved={onPinSaved}
        />,
      );

      expect(screen.getByText(/Configurar PIN de seguridad/i)).toBeInTheDocument();

      const newPinInput = screen.getByLabelText(/Crear PIN/i);
      const confirmPinInput = screen.getByLabelText(/Confirmar PIN/i);
      const saveBtn = screen.getByRole('button', { name: /Guardar PIN/i });

      // PIN no coincide
      fireEvent.change(newPinInput, { target: { value: '1234' } });
      fireEvent.change(confirmPinInput, { target: { value: '5678' } });
      fireEvent.click(saveBtn);

      await waitFor(() => {
        expect(screen.getByText(/La confirmación no coincide/i)).toBeInTheDocument();
      });

      // PIN coincide
      fireEvent.change(confirmPinInput, { target: { value: '1234' } });
      fireEvent.click(saveBtn);

      await waitFor(() => {
        expect(screen.getByText(/¡PIN configurado exitosamente!/i)).toBeInTheDocument();
      });

      await waitFor(
        () => {
          expect(onPinSaved).toHaveBeenCalled();
          expect(onOpenChange).toHaveBeenCalledWith(false);
        },
        { timeout: 1500 },
      );

      expect(security.hasDashboardPin(2)).toBe(true);
    });

    it('valida el PIN actual antes de actualizar cuando hasPin es true', async () => {
      await security.setDashboardPin('1111', 3);
      const onPinSaved = vi.fn();
      const onOpenChange = vi.fn();

      render(
        <ManageDashboardPinDialog
          open={true}
          onOpenChange={onOpenChange}
          tenantId={3}
          hasPin={true}
          onPinSaved={onPinSaved}
        />,
      );

      expect(screen.getByText(/Cambiar PIN de seguridad/i)).toBeInTheDocument();

      const currentPinInput = screen.getByLabelText(/PIN actual/i);
      const newPinInput = screen.getByLabelText(/Nuevo PIN/i);
      const confirmPinInput = screen.getByLabelText(/Confirmar PIN/i);
      const updateBtn = screen.getByRole('button', { name: /Actualizar PIN/i });

      // PIN actual erróneo
      fireEvent.change(currentPinInput, { target: { value: '9999' } });
      fireEvent.change(newPinInput, { target: { value: '2222' } });
      fireEvent.change(confirmPinInput, { target: { value: '2222' } });
      fireEvent.click(updateBtn);

      await waitFor(() => {
        expect(screen.getByText(/El PIN actual es incorrecto/i)).toBeInTheDocument();
      });

      // PIN actual correcto
      fireEvent.change(currentPinInput, { target: { value: '1111' } });
      fireEvent.click(updateBtn);

      await waitFor(() => {
        expect(screen.getByText(/¡PIN actualizado exitosamente!/i)).toBeInTheDocument();
      });

      await waitFor(
        () => {
          expect(onPinSaved).toHaveBeenCalled();
        },
        { timeout: 1500 },
      );
    });
  });
});
