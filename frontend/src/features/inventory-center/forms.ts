/**
 * Hook useProductForm: integra react-hook-form + zodResolver + StoreProductSchema.
 * Usado por CreateProductDialog y EditProductDialog.
 *
 * Caracteristicas:
 * - Resuelve y carga lookups (brands, categories, tags, warranty, rate types).
 * - Convierte el output del form al shape que espera el backend.
 * - Soporta initial values (modo edit).
 * - Mapea errores del backend a campos del form.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { useCallback, useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';

import { type StoreProductInput, type StoreProductValues, StoreProductSchema } from './schemas';
import { useCreateProduct, useUpdateProduct } from './api';

export type ProductFormMode = 'create' | 'edit';

export interface UseProductFormOptions {
  mode: ProductFormMode;
  productId?: number;
  initialValues?: Partial<StoreProductValues>;
  onSuccess?: (data: unknown) => void;
}

// Defaults sensatos para create. Fijos (no dependen del render) para que
// useForm no los recree cada vez.
const CREATE_DEFAULTS: StoreProductValues = {
  name: '',
  description: '',
  long_description: '',
  sku: '',
  barcode: '',
  image_url: '',
  tracking_type: 'quantity',
  unit_of_measure: 'unit',
  track_stock: true,
  brand_id: undefined,
  category_ids: [],
  tag_ids: [],
  base_price: undefined,
  sale_currency: 'USD',
  sale_exchange_rate_type_id: undefined,
  min_stock: undefined,
  max_stock: undefined,
  reorder_quantity: undefined,
  warranty_policy_id: undefined,
  is_active: true,
};

export function useProductForm({
  mode,
  productId,
  initialValues,
  onSuccess,
}: UseProductFormOptions) {
  // Construimos defaultValues una sola vez por montaje del form (o cuando
  // cambia productId en modo edit). El padre SIEMPRE debe memoizar
  // initialValues O pasar solo los campos primitivos para que la
  // dependencia no cambie de referencia cada render.
  const formId = mode === 'edit' && productId !== undefined ? productId : null;

  const stableDefaults = useMemo<StoreProductValues>(() => {
    if (mode === 'edit' && initialValues) {
      return { ...CREATE_DEFAULTS, ...initialValues };
    }
    return CREATE_DEFAULTS;
    // Solo recalcular si cambia el id del producto en modo edit. Si el
    // padre pasa initialValues con referencia nueva cada render, sera
    // responsabilidad del padre memoizarlo (recomendado).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formId]);

  const form = useForm<StoreProductInput, unknown, StoreProductValues>({
    resolver: zodResolver(StoreProductSchema),
    defaultValues: stableDefaults as StoreProductInput,
    mode: 'onBlur',
  });

  // Cuando cambia el productId en modo edit, reseteamos el form con
  // los nuevos initialValues. NO dependemos de initialValues directamente
  // porque su referencia cambia cada render y eso causa el loop infinito
  // ('Maximum update depth exceeded') que vimos reportado por el usuario.
  const handleReset = useCallback(
    (values: Partial<StoreProductValues>) => {
      form.reset({ ...CREATE_DEFAULTS, ...values });
    },
    [form],
  );
  useEffect(() => {
    if (mode === 'edit' && initialValues && formId !== null) {
      handleReset(initialValues);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [formId]);

  const create = useCreateProduct();
  const update = useUpdateProduct();
  const mutation = mode === 'create' ? create : update;

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      // StoreProductSchema ya hace el .transform que limpia vacios.
      const payload = values;
      let result: unknown;
      if (mode === 'create') {
        result = await create.mutateAsync(payload);
        toast.success('Producto creado correctamente.');
      } else {
        if (!productId) throw new Error('productId requerido en modo edit');
        result = await update.mutateAsync({ id: productId, ...payload });
        toast.success('Producto actualizado correctamente.');
      }
      onSuccess?.(result);
    } catch (err) {
      // Errores 422 del backend: mapear a campos del form.
      if (err instanceof Error && 'fieldErrors' in err) {
        const fieldErrors = (err as { fieldErrors: Record<string, string[]> }).fieldErrors;
        for (const [field, messages] of Object.entries(fieldErrors)) {
          // Cast a never: el path del backend puede incluir notacion con puntos
          // (ej: "category_ids.0") que el type de setError no soporta estrictamente.
          form.setError(field as never, {
            type: 'server',
            message: messages[0],
          });
        }
      } else {
        toast.error(err instanceof Error ? err.message : 'Error al guardar el producto.');
      }
    }
  });

  return {
    form,
    onSubmit,
    isSubmitting: mutation.isPending,
    error: mutation.error,
  };
}