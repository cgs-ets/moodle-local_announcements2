
import { create } from 'zustand'

export type Filters = {
  categories: string[];
  types: string[];
  campus: string[];
  status: string[];
  staff: string[];
  courses: string[];
  name: string;
  reviewstep: string[];
}

type FilterStore = Filters & {
  setState: (newState: Filters | null) => void,
  reset: () => void,
}

const defaults: Filters = {
  status: [],
  categories: [],
  types: [],
  campus: [],
  staff: [],
  courses: [],
  name: '',
  reviewstep: [],
};

const useFilterStore = create<FilterStore>((set) => ({
  ...defaults,
  setState: (newState) => set(newState || defaults),
  reset: () => set(defaults),
}))


export { 
  defaults,
  useFilterStore, 
};