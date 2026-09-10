import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface UiModeState {
  isSimpleMode: boolean;
  toggleSimpleMode: () => void;
  setSimpleMode: (simple: boolean) => void;
}

export const useUiModeStore = create<UiModeState>()(
  persist(
    (set) => ({
      isSimpleMode: true, // Modo Fácil activo por defecto para Repuestos Avilacar
      toggleSimpleMode: () => set((state) => ({ isSimpleMode: !state.isSimpleMode })),
      setSimpleMode: (simple: boolean) => set({ isSimpleMode: simple }),
    }),
    {
      name: 'sdi-ui-mode',
    },
  ),
);
