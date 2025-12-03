
import { create } from 'zustand'

export type Workflow = {
  approvals: any[],
};

type WorkflowStore = Workflow & {
  setState: (newState: Workflow | null) => void,
  setApprovals: (approvals: any[]) => void,
  reset: () => void,
}

const defaults: Workflow = {
  approvals: [],
};

const useWorkflowStore = create<WorkflowStore>((set) => ({
  ...defaults,
  setState: (newState) => set(newState || defaults),
  setApprovals: (approvals: any[]) => {
    set({approvals: approvals})
  },
  reset: () => set(defaults),
}))


export { 
  defaults,
  useWorkflowStore, 
};