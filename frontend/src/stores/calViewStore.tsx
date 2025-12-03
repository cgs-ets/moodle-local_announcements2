
import { create } from 'zustand'

export type CalView = {
  type: string;
  year: string;
  month: string;
  term: string;
}

type CalViewStore = CalView & {
  setState: (newState: CalView | null) => void,
  reset: () => void,
}

const defaults: CalView = {
  type: 'calendar',
  year: '',
  month: '',
  term: '',
};

const useCalViewStore = create<CalViewStore>((set) => ({
  ...defaults,
  setState: (newState) => set(newState || defaults),
  reset: () => set(defaults),
}))


export { 
  defaults,
  useCalViewStore, 
};