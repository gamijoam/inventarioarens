/**
 * ImagePicker.tsx — File picker + drag-drop + paste-from-clipboard + preview.
 *
 * Usado en ImageGallery para agregar imagenes nuevas. Soporta:
 *  - Click en el dropzone para abrir file picker
 *  - Drag & drop de archivos
 *  - Paste desde el portapapeles (Ctrl+V sobre el componente)
 *  - Preview del archivo seleccionado antes de subir
 *  - Validacion client-side (mime, size) con mensajes en espanol
 *
 * El upload se dispara desde el padre via callback `onFileSelected`.
 * El padre decide si sube inmediatamente o espera confirmacion.
 */
import { useEffect, useRef, useState } from 'react';
import { ImagePlus, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';

interface ImagePickerProps {
  onFileSelected: (file: File) => void;
  accept?: string;
  maxSize?: number; // bytes (default 5 MB = 5 * 1024 * 1024)
  disabled?: boolean;
  className?: string;
  /** Texto custom opcional para mostrar en el dropzone. */
  hint?: string;
}

const DEFAULT_ACCEPT = 'image/jpeg,image/png,image/webp';
const DEFAULT_MAX_SIZE = 5 * 1024 * 1024;

/**
 * Valida un File contra las reglas de ImageProcessor del backend.
 * Devuelve string de error si falla, o null si OK.
 */
function validateImage(file: File, maxSize: number): string | null {
  const validMimes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!validMimes.includes(file.type)) {
    return 'La imagen debe ser JPG, PNG o WebP.';
  }
  if (file.size > maxSize) {
    const mb = (maxSize / (1024 * 1024)).toFixed(0);
    return `La imagen no puede pesar mas de ${mb} MB.`;
  }
  return null;
}

export function ImagePicker({
  onFileSelected,
  accept = DEFAULT_ACCEPT,
  maxSize = DEFAULT_MAX_SIZE,
  disabled = false,
  className,
  hint = 'Arrastra una imagen, haz click para seleccionar, o pega con Ctrl+V',
}: ImagePickerProps) {
  const [dragOver, setDragOver] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);

  function handleFile(file: File | null | undefined) {
    if (!file) return;
    const error = validateImage(file, maxSize);
    if (error) {
      toast.error(error);
      return;
    }
    // Liberar preview previo.
    if (preview) URL.revokeObjectURL(preview);
    setPreview(URL.createObjectURL(file));
    onFileSelected(file);
  }

  // Paste handler: captura imagenes pegadas desde el portapapeles.
  useEffect(() => {
    if (disabled) return;
    function onPaste(e: ClipboardEvent) {
      const items = e.clipboardData?.items;
      if (!items) return;
      for (const item of items) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
          const file = item.getAsFile();
          if (file) {
            e.preventDefault();
            handleFile(file);
            return;
          }
        }
      }
    }
    window.addEventListener('paste', onPaste);
    return () => window.removeEventListener('paste', onPaste);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [disabled, maxSize]);

  return (
    <div className={cn('space-y-3', className)}>
      <div
        role="button"
        tabIndex={0}
        aria-disabled={disabled}
        onClick={() => !disabled && inputRef.current?.click()}
        onKeyDown={(e) => {
          if ((e.key === 'Enter' || e.key === ' ') && !disabled) {
            e.preventDefault();
            inputRef.current?.click();
          }
        }}
        onDragOver={(e) => {
          if (disabled) return;
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          if (disabled) return;
          e.preventDefault();
          setDragOver(false);
          handleFile(e.dataTransfer.files?.[0]);
        }}
        className={cn(
          'relative flex flex-col items-center justify-center gap-2 rounded border border-dashed p-6 text-center transition-colors',
          dragOver
            ? 'border-primary bg-primary/5'
            : 'border-border bg-surface hover:border-primary/50',
          disabled && 'cursor-not-allowed opacity-50',
          !disabled && 'cursor-pointer',
        )}
        data-testid="image-picker-dropzone"
      >
        <ImagePlus className="size-8 text-text-muted" />
        <p className="text-sm text-text-muted">{hint}</p>
        <p className="text-xs text-text-muted">
          JPG, PNG o WebP. Max {Math.round(maxSize / (1024 * 1024))} MB.
        </p>
        <input
          ref={inputRef}
          type="file"
          accept={accept}
          className="hidden"
          data-testid="image-picker-input"
          onChange={(e) => handleFile(e.target.files?.[0])}
          disabled={disabled}
        />
      </div>

      {preview && (
        <div className="space-y-2 rounded border border-border bg-surface p-3">
          <div className="flex items-center justify-between">
            <p className="text-xs font-semibold uppercase text-text-muted">
              Vista previa
            </p>
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={() => {
                URL.revokeObjectURL(preview);
                setPreview(null);
                if (inputRef.current) inputRef.current.value = '';
              }}
              aria-label="Quitar imagen seleccionada"
            >
              <X className="size-4" />
            </Button>
          </div>
          <img
            src={preview}
            alt="Vista previa"
            className="mx-auto max-h-48 rounded object-contain"
          />
          <p className="text-center text-xs text-text-muted">
            Lista para subir. Confirma desde el formulario padre.
          </p>
        </div>
      )}
    </div>
  );
}
